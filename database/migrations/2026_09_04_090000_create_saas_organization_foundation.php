<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Introduce la frontera SaaS sobre el modelo multitienda existente.
 *
 * Una instalación heredada representa un solo cliente de EcuaPos, por
 * lo que todas sus tiendas se agrupan dentro de una misma organización.
 * Las tiendas conservan inventario, caja, permisos y SRI independientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 30)->default('MEMBER');
            $table->string('status', 30)->default('ACTIVE');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('stores', function (Blueprint $table) {
            // Se mantiene nullable durante la transición para no romper
            // integraciones antiguas; todas las tiendas reales existentes
            // quedan asignadas abajo y las nuevas se validan en aplicación.
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->foreign('organization_id')->references('id')->on('organizations')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['organization_id', 'is_active']);
        });

        $storeIds = DB::table('stores')->orderBy('id')->pluck('id');
        if ($storeIds->isEmpty()) {
            return;
        }

        $organizationName = DB::table('settings')
            ->where('key', 'company_name')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->value('value')
            ?: DB::table('stores')->where('is_default', true)->value('name')
            ?: DB::table('stores')->orderBy('id')->value('name')
            ?: 'Mi organización';

        $baseSlug = Str::slug($organizationName) ?: 'organizacion';
        $slug = $baseSlug;
        $suffix = 2;
        while (DB::table('organizations')->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => $organizationName,
            'slug' => $slug,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('stores')->whereIn('id', $storeIds)->update([
            'organization_id' => $organizationId,
            'updated_at' => $now,
        ]);

        $userIds = DB::table('user_store')
            ->whereIn('store_id', $storeIds)
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        // Preferimos como propietario a un administrador existente. Si
        // la instalación no tiene roles legibles, se usa el primer usuario.
        $ownerId = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereIn('mhr.store_id', $storeIds)
            ->whereIn('mhr.model_id', $userIds)
            ->where('mhr.model_type', 'App\\Models\\User')
            ->whereRaw('LOWER(r.name) IN (?, ?)', ['admin', 'super_admin'])
            ->orderByRaw("CASE WHEN LOWER(r.name) = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('mhr.model_id')
            ->value('mhr.model_id')
            ?: $userIds->first();

        $memberships = $userIds->map(fn ($userId) => [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role' => (int) $userId === (int) $ownerId ? 'OWNER' : 'MEMBER',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('organization_user')->insert($memberships);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
