<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class OfflineCustomerSyncController extends AppBaseController
{
    public function store(Request $request): CustomerResource
    {
        $storeId = $this->requireCurrentStoreId();
        $this->assertDeviceCredential($request, $storeId);
        $input = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'identification' => ['nullable', 'string', 'max:30'],
            'tipo_identificacion' => ['nullable', 'in:04,05,06,07,08'],
            'es_consumidor_final' => ['nullable', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'numeric'],
            'country' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'dob' => ['nullable', 'date'],
        ]);
        $input['store_id'] = $storeId;
        $input['identification'] = trim((string) ($input['identification'] ?? '')) ?: null;

        $customer = DB::transaction(function () use ($input, $storeId) {
            $clientUuid = $input['client_uuid'];
            unset($input['client_uuid']);
            $mappedCustomerId = DB::table('offline_customer_identities')
                ->where('store_id', $storeId)
                ->where('client_uuid', $clientUuid)
                ->value('customer_id');
            if ($mappedCustomerId) {
                return Customer::where('store_id', $storeId)->findOrFail($mappedCustomerId);
            }

            if ($input['identification']) {
                $existing = Customer::where('store_id', $storeId)
                    ->where('identification', $input['identification'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->attachOfflineIdentity($existing, $storeId, $clientUuid);
                }
            }

            $emailMatch = Customer::where('store_id', $storeId)
                ->where('email', $input['email'])
                ->lockForUpdate()
                ->first();
            if ($emailMatch) {
                $differentIdentification = $input['identification']
                    && $emailMatch->identification
                    && $emailMatch->identification !== $input['identification'];
                if ($differentIdentification) {
                    throw ValidationException::withMessages([
                        'email' => ['El correo ya pertenece a otro cliente de esta tienda.'],
                    ]);
                }

                return $this->attachOfflineIdentity($emailMatch, $storeId, $clientUuid);
            }

            try {
                $customer = Customer::create($input);
                return $this->attachOfflineIdentity($customer, $storeId, $clientUuid);
            } catch (QueryException $exception) {
                $raced = Customer::where('store_id', $storeId)
                    ->where(function ($query) use ($input) {
                        $query->where('email', $input['email']);
                        if ($input['identification']) {
                            $query->orWhere('identification', $input['identification']);
                        }
                    })->first();
                if ($raced) {
                    return $this->attachOfflineIdentity($raced, $storeId, $clientUuid);
                }
                throw $exception;
            }
        });

        return new CustomerResource($customer->fresh());
    }

    private function assertDeviceCredential(Request $request, int $storeId): void
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token || ! str_starts_with($token->name, 'offline-sync:')) {
            throw new AccessDeniedHttpException('Esta ruta requiere una credencial de sincronización del dispositivo.');
        }
        if ($token->created_at?->copy()->addHours(max(1, (int) config('saas.offline_lease_hours', 12)))->isPast()) {
            $token->delete();
            throw new AccessDeniedHttpException('La credencial offline venció. Conéctate nuevamente para renovarla.');
        }
        if (! $request->user()->tokenCan("store:{$storeId}")) {
            throw new AccessDeniedHttpException('La credencial no pertenece a la tienda activa.');
        }
    }

    private function attachOfflineIdentity(Customer $customer, int $storeId, string $clientUuid): Customer
    {
        DB::table('offline_customer_identities')->insertOrIgnore([
            'store_id' => $storeId,
            'client_uuid' => $clientUuid,
            'customer_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $customer;
    }
}
