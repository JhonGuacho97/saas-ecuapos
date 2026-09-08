<?php

namespace Tests\Feature;

use App\Models\CouponCode;
use App\Models\MailTemplate;
use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPlan;
use App\Models\SaaSPayment;
use App\Models\SmsSetting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CriticalTenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_payload_cannot_promote_a_tenant_user_to_super_admin(): void
    {
        [$organization, $store, $actor] = $this->tenant('perfil');
        Sanctum::actingAs($actor, ['*']);

        $this->withHeaders($this->headers($organization, $store))
            ->postJson('/api/update-profile', [
                'first_name' => 'Usuario',
                'last_name' => 'Seguro',
                'email' => $actor->email,
                'phone' => '0999999999',
                'is_super_admin' => true,
            ])
            ->assertOk();

        $this->assertFalse((bool) $actor->fresh()->is_super_admin);
    }

    public function test_store_admin_cannot_read_or_reset_a_user_from_another_tenant(): void
    {
        [$organization, $store, $actor] = $this->tenant('origen');
        [, $foreignStore, $foreignUser] = $this->tenant('ajeno');
        $foreignUser->stores()->sync([$foreignStore->id]);

        $platformAdmin = User::create([
            'first_name' => 'Administrador',
            'last_name' => 'Plataforma',
            'email' => 'platform-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0988888888',
            'password' => Hash::make('secret123'),
            'language' => 'sp',
        ]);
        $platformAdmin->forceFill(['is_super_admin' => true])->save();
        $organization->users()->attach($platformAdmin->id, [
            'role' => Organization::ROLE_OWNER,
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $platformAdmin->stores()->attach($store->id);

        setPermissionsTeamId($store->id);
        foreach (['manage_users', 'change_user_passwords'] as $permissionName) {
            $actor->givePermissionTo(Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]));
        }
        // Reproduce el camino del administrador tenant con todos los
        // permisos, que antes partía de User::query() sin excluir cuentas
        // globales de plataforma.
        $actor->syncPermissions(Permission::all());
        Sanctum::actingAs($actor, ['*']);
        $headers = $this->headers($organization, $store);

        $this->withHeaders($headers)->getJson('/api/users?returnAll=true')
            ->assertOk()
            ->assertJsonMissing(['email' => $platformAdmin->email])
            ->assertJsonMissing(['email' => $foreignUser->email]);

        $this->withHeaders($headers)->getJson('/api/users?returnAll=true&filter[search]=platform')
            ->assertOk()
            ->assertJsonMissing(['email' => $platformAdmin->email]);

        $this->withHeaders($headers)->getJson("/api/users/{$foreignUser->id}")
            ->assertForbidden();

        $this->withHeaders($headers)->postJson("/api/users/{$foreignUser->id}/change-password", [
            'password' => 'ClaveNueva123',
            'password_confirmation' => 'ClaveNueva123',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('secret123', $foreignUser->fresh()->password));
    }

    public function test_ride_and_xml_are_not_public_endpoints(): void
    {
        $this->get('/api/electronic-invoices/999999/ride')->assertUnauthorized();
        $this->get('/api/electronic-invoices/999999/xml')->assertUnauthorized();
    }

    public function test_request_with_multiple_stores_cannot_continue_without_an_explicit_store(): void
    {
        [$organization, $store, $actor] = $this->tenant('multitienda');
        $secondStore = Store::create([
            'organization_id' => $organization->id,
            'name' => 'Segunda tienda',
            'slug' => 'segunda-'.Str::lower(Str::random(10)),
            'is_active' => true,
        ]);
        $actor->stores()->attach($secondStore->id);
        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/api/current-organization')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Debe seleccionar una tienda para continuar.');

        $this->withHeaders($this->headers($organization, $store))
            ->getJson('/api/current-organization')
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id);
    }

    public function test_store_templates_and_coupons_do_not_cross_tenant_boundaries(): void
    {
        [$firstOrganization, $firstStore, $firstUser] = $this->tenant('config-a');
        [$secondOrganization, $secondStore, $secondUser] = $this->tenant('config-b');

        MailTemplate::create([
            'template_name' => 'Plantilla global de prueba',
            'subject' => 'Asunto',
            'content' => 'Contenido base',
            'type' => 'isolation-'.Str::lower(Str::random(8)),
            'status' => true,
        ]);
        foreach ([[$firstUser, $firstStore], [$secondUser, $secondStore]] as [$user, $store]) {
            setPermissionsTeamId($store->id);
            foreach (['manage_email_templates', 'manage_products'] as $name) {
                $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
            }
        }

        Sanctum::actingAs($firstUser, ['*']);
        $this->withHeaders($this->headers($firstOrganization, $firstStore))
            ->getJson('/api/mail-templates')->assertOk();
        $firstTemplate = MailTemplate::where('store_id', $firstStore->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($secondUser, ['*']);
        $this->withHeaders($this->headers($secondOrganization, $secondStore))
            ->getJson("/api/mail-templates/{$firstTemplate->id}/edit")
            ->assertForbidden();

        CouponCode::create($this->couponData($firstStore->id, 'SOLO-A'));
        CouponCode::create($this->couponData($secondStore->id, 'SOLO-B'));
        $this->withHeaders($this->headers($secondOrganization, $secondStore))
            ->getJson('/api/coupon-codes')
            ->assertOk()
            ->assertJsonFragment(['code' => 'SOLO-B'])
            ->assertJsonMissing(['code' => 'SOLO-A']);

        SmsSetting::create(['key' => 'isolation_gateway', 'value' => 'global']);
        SmsSetting::create([
            'store_id' => $firstStore->id,
            'key' => 'isolation_gateway',
            'value' => 'tenant-a',
        ]);
        $this->assertSame('tenant-a', SmsSetting::effectiveValue($firstStore->id, 'isolation_gateway'));
        $this->assertSame('global', SmsSetting::effectiveValue($secondStore->id, 'isolation_gateway'));
    }

    public function test_login_logs_are_visible_and_deletable_only_in_their_store(): void
    {
        [$firstOrganization, $firstStore, $firstUser] = $this->tenant('logs-a');
        [$secondOrganization, $secondStore, $secondUser] = $this->tenant('logs-b');
        $ownLog = LoginLog::create([
            'user_id' => $firstUser->id,
            'email' => $firstUser->email,
            'ip_address' => '10.0.0.1',
            'status' => 'success',
            'logged_at' => now(),
        ]);
        $foreignLog = LoginLog::create([
            'user_id' => $secondUser->id,
            'email' => $secondUser->email,
            'ip_address' => '10.0.0.2',
            'status' => 'success',
            'logged_at' => now(),
        ]);

        setPermissionsTeamId($firstStore->id);
        $firstUser->givePermissionTo(Permission::firstOrCreate([
            'name' => 'manage_login_logs',
            'guard_name' => 'web',
        ]));
        Sanctum::actingAs($firstUser, ['*']);
        $headers = $this->headers($firstOrganization, $firstStore);

        $this->withHeaders($headers)->getJson('/api/login-logs')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownLog->id])
            ->assertJsonMissing(['id' => $foreignLog->id]);

        $this->withHeaders($headers)->deleteJson("/api/login-logs/{$foreignLog->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('login_logs', ['id' => $foreignLog->id]);
    }

    public function test_private_tenant_files_are_resolved_from_the_active_store_only(): void
    {
        [$firstOrganization, $firstStore, $firstUser] = $this->tenant('files-a');
        [$secondOrganization, $secondStore, $secondUser] = $this->tenant('files-b');
        $filename = 'same-report.xlsx';
        $disk = Storage::disk('tenant_private');
        $disk->put("tenants/{$firstOrganization->id}/stores/{$firstStore->id}/excel/{$filename}", 'tenant-a');
        $disk->put("tenants/{$secondOrganization->id}/stores/{$secondStore->id}/excel/{$filename}", 'tenant-b');

        Sanctum::actingAs($firstUser, ['*']);
        $response = $this->withHeaders($this->headers($firstOrganization, $firstStore))
            ->get("/api/tenant-files/excel/{$filename}");
        $response->assertOk();

        ob_start();
        $response->baseResponse->sendContent();
        $content = ob_get_clean();
        $this->assertSame('tenant-a', $content);
    }

    public function test_tenant_administrator_cannot_download_the_shared_database_backup(): void
    {
        [$organization, $store, $user] = $this->tenant('backup');
        setPermissionsTeamId($store->id);
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => 'manage_setting',
            'guard_name' => 'web',
        ]));
        Sanctum::actingAs($user, ['*']);

        $this->withHeaders($this->headers($organization, $store))
            ->get('/api/backup/download')
            ->assertNotFound();
    }

    public function test_tenant_cannot_mutate_platform_languages_or_currencies(): void
    {
        [$organization, $store, $user] = $this->tenant('catalogos-globales');
        setPermissionsTeamId($store->id);
        foreach (['manage_language', 'manage_currency'] as $permissionName) {
            $user->givePermissionTo(Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]));
        }
        Sanctum::actingAs($user, ['*']);
        $headers = $this->headers($organization, $store);

        $this->withHeaders($headers)->postJson('/api/languages', [
            'name' => 'Tenant language',
            'iso_code' => 'zz',
        ])->assertMethodNotAllowed();

        $this->withHeaders($headers)->postJson('/api/currencies', [
            'name' => 'Tenant currency',
            'code' => 'ZZZ',
            'symbol' => 'Z',
        ])->assertMethodNotAllowed();
    }

    public function test_super_admin_can_view_a_private_payment_proof_without_a_public_url(): void
    {
        [$organization] = $this->tenant('proof');
        $subscription = OrganizationSubscription::where('organization_id', $organization->id)->firstOrFail();
        $plan = $subscription->plan;
        $path = "saas-payment-proofs/{$organization->id}/proof.png";
        Storage::fake('saas_private');
        Storage::disk('saas_private')->put($path, 'private-proof');

        $payment = SaaSPayment::create([
            'organization_id' => $organization->id,
            'organization_subscription_id' => $subscription->id,
            'saas_plan_id' => $plan->id,
            'amount' => 10,
            'currency' => 'USD',
            'status' => SaaSPayment::STATUS_PENDING,
            'method' => 'transfer',
            'proof_path' => $path,
        ]);
        $superAdmin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0977777777',
            'password' => Hash::make('secret123'),
            'language' => 'sp',
        ]);
        $superAdmin->forceFill([
            'is_super_admin' => true,
            'two_factor_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        Sanctum::actingAs($superAdmin, ['*']);

        $response = $this->get("/api/super-admin/payments/{$payment->id}/proof");
        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        ob_start();
        $response->baseResponse->sendContent();
        $content = ob_get_clean();
        $this->assertSame('private-proof', $content);
    }

    private function tenant(string $prefix): array
    {
        $suffix = Str::lower(Str::random(10));
        $organization = Organization::create([
            'name' => "Organización {$prefix} {$suffix}",
            'slug' => "{$prefix}-{$suffix}",
            'is_active' => true,
        ]);
        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => "Tienda {$prefix}",
            'slug' => "tienda-{$prefix}-{$suffix}",
            'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'Usuario',
            'last_name' => ucfirst($prefix),
            'email' => "{$prefix}-{$suffix}@example.test",
            'phone' => '0999999999',
            'password' => Hash::make('secret123'),
            'language' => 'sp',
        ]);
        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $user->stores()->attach($store->id);

        $plan = SaaSPlan::create([
            'code' => "plan-{$prefix}-{$suffix}",
            'name' => "Plan {$prefix}",
            'price' => 10,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'billing_interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 0,
            'features' => ['*'],
            'is_active' => true,
        ]);
        OrganizationSubscription::create([
            'organization_id' => $organization->id,
            'saas_plan_id' => $plan->id,
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'current_period_starts_at' => now()->subDay(),
            'current_period_ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        return [$organization, $store, $user];
    }

    private function headers(Organization $organization, Store $store): array
    {
        return [
            'X-Organization-Id' => (string) $organization->id,
            'X-Store-Id' => (string) $store->id,
        ];
    }

    private function couponData(int $storeId, string $code): array
    {
        return [
            'store_id' => $storeId,
            'name' => $code,
            'code' => $code,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'how_many_time_can_use' => 5,
            'discount_type' => CouponCode::FIXED,
            'discount' => 1,
        ];
    }
}
