<?php

namespace Database\Seeders;

use App\Models\ElectronicInvoice;
use App\Models\POSRegister;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fase 2 de la migración multitienda -- backfillea TODOS los datos
 * existentes hacia una única "Store inicial", preservando el historial
 * completo (nada se borra ni se re-escribe destructivamente). Ver el
 * documento de planificación multitienda, sección 17.
 *
 * Idempotente a propósito -- cada paso solo toca filas con store_id/
 * warehouse_id todavía NULL, así que correr esto más de una vez (o
 * después de agregar una segunda Store real más adelante) no pisa datos
 * ya migrados correctamente.
 */
class MigrateToInitialStoreSeeder extends Seeder
{
    public function run(): void
    {
        // En una instalación SaaS nueva no existe todavía ningún tenant. Esta
        // migración nació para convertir instalaciones antiguas de un solo
        // negocio y antes creaba una tienda "EcuaPos" incluso sobre una base
        // completamente vacía. Además de ensuciar el alta inicial, las
        // migraciones SaaS posteriores convertían esa tienda artificial en una
        // organización con una suscripción heredada.
        //
        // Las tablas de referencia (permisos, idiomas, unidades, países,
        // plantillas y settings globales) sí pueden contener filas porque
        // migraciones históricas las inicializan. No son señal de un tenant
        // heredado; por eso solo miramos usuarios y datos operativos.
        if (! Store::query()->exists() && ! $this->hasLegacyTenantData()) {
            $this->removeOrphanedTenantRoles();
            $this->command?->info('Base SaaS nueva: no se crea una tienda heredada.');

            return;
        }

        DB::transaction(function () {
            $store = $this->resolveInitialStore();

            $this->backfillSimpleTable('warehouses', $store->id);
            $this->backfillSimpleTable('products', $store->id);
            $this->backfillSimpleTable('main_products', $store->id);
            $this->backfillSimpleTable('product_categories', $store->id);
            $this->backfillSimpleTable('brands', $store->id);
            $this->backfillSimpleTable('variations', $store->id);
            $this->backfillSimpleTable('variation_types', $store->id);
            $this->backfillSimpleTable('customers', $store->id);
            $this->backfillSimpleTable('suppliers', $store->id);
            $this->backfillSimpleTable('expense_categories', $store->id);

            $this->backfillSettings($store);
            $this->backfillUserStoreAndWarehouse($store);
            $this->backfillPosRegisterWarehouse();
            $this->backfillElectronicInvoicesContext($store);
            $this->backfillRoleTeams($store);
        });

        $this->command?->info('Store inicial: ' . Store::first()?->name . ' (id=' . Store::first()?->id . ')');
    }

    /**
     * Determina si realmente hay un negocio anterior que deba migrarse.
     * Mantener esta lista limitada a entidades tenant evita confundir los
     * catálogos globales sembrados por migraciones antiguas con una empresa.
     */
    private function hasLegacyTenantData(): bool
    {
        foreach ([
            'users',
            'warehouses',
            'products',
            'main_products',
            'product_categories',
            'brands',
            'variations',
            'variation_types',
            'customers',
            'suppliers',
            'expense_categories',
            'sales',
            'purchases',
        ] as $table) {
            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seeders históricos crean el rol tenant "admin" antes de que exista la
     * tabla stores. En un SaaS nuevo ese rol global no pertenece a nadie y
     * Spatie lo considera candidato para cualquier team, impidiendo crear el
     * primer rol admin de una tienda. Los permisos sí permanecen como catálogo
     * global; solo se eliminan roles huérfanos cuando ya comprobamos que no hay
     * datos tenant que preservar.
     */
    private function removeOrphanedTenantRoles(): void
    {
        DB::table('role_has_permissions')->delete();
        DB::table('model_has_roles')->delete();
        DB::table('roles')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Si ya existe una Store (ej. porque este seeder ya corrió antes),
     * la reutiliza en vez de crear una segunda -- así es seguro
     * re-ejecutar este seeder sin duplicar la tienda inicial.
     */
    private function resolveInitialStore(): Store
    {
        $existing = Store::first();
        if ($existing) {
            return $existing;
        }

        $companyName = Setting::whereNull('store_id')->where('key', 'company_name')->value('value');
        $companyName = $companyName ?: 'EcuaPos';

        $slug = Str::slug($companyName) ?: 'tienda-principal';
        $original = $slug;
        $suffix = 1;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $original . '-' . (++$suffix);
        }

        return Store::create([
            'name' => $companyName,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    /**
     * Todas las tablas de catálogo comparten el mismo patrón: una sola
     * store existía implícitamente, así que TODA fila existente pasa a
     * pertenecer a ella. whereNull('store_id') es lo que hace esto
     * idempotente.
     */
    private function backfillSimpleTable(string $table, int $storeId): void
    {
        DB::table($table)->whereNull('store_id')->update(['store_id' => $storeId]);
    }

    /**
     * Las filas de settings existentes (store_id NULL) se DEJAN tal cual
     * -- siguen siendo el fallback de sistema/legacy -- y se agrega una
     * copia con store_id = la tienda inicial para cada una, que es la
     * que el código empieza a preferir una vez que exista contexto de
     * tienda activa (Fase 3+). No se duplica si ya existe una copia para
     * esta store (ej. si el seeder corrió antes).
     */
    private function backfillSettings(Store $store): void
    {
        if (Setting::where('store_id', $store->id)->exists()) {
            return;
        }

        $originales = Setting::whereNull('store_id')->get(['key', 'value']);
        foreach ($originales as $setting) {
            Setting::create([
                'store_id' => $store->id,
                'key' => $setting->key,
                'value' => $setting->value,
            ]);
        }
    }

    /**
     * Un usuario existente gana acceso a la única Store que existe hoy.
     * default_warehouse_id (si estaba puesto) se migra 1 a 1 a
     * user_warehouse -- misma semántica: sin fila = ve todas las
     * sucursales de la tienda (igual que default_warehouse_id nulo hoy).
     */
    private function backfillUserStoreAndWarehouse(Store $store): void
    {
        $users = User::all(['id', 'default_warehouse_id']);
        foreach ($users as $user) {
            DB::table('user_store')->insertOrIgnore([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($user->default_warehouse_id) {
                DB::table('user_warehouse')->insertOrIgnore([
                    'user_id' => $user->id,
                    'warehouse_id' => $user->default_warehouse_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * pos_register nunca guardó en qué sucursal se abrió una caja. Se
     * infiere de la primera venta hecha por ese mismo usuario dentro de
     * la ventana de la sesión (creación de la caja -> cierre, o "ahora"
     * si sigue abierta). Si una caja no tiene ninguna venta asociada
     * (nunca se vendió nada en esa sesión), queda NULL -- no hay forma
     * de reconstruir ese dato con certeza, y se documenta como tal en el
     * plan de migración (sección 17, riesgo del paso 6).
     */
    private function backfillPosRegisterWarehouse(): void
    {
        $registers = POSRegister::whereNull('warehouse_id')->get(['id', 'user_id', 'created_at', 'closed_at']);

        foreach ($registers as $register) {
            $warehouseId = Sale::where('user_id', $register->user_id)
                ->where('created_at', '>=', $register->created_at)
                ->where('created_at', '<=', $register->closed_at ?? now())
                ->orderBy('created_at')
                ->value('warehouse_id');

            if ($warehouseId) {
                $register->warehouse_id = $warehouseId;
                $register->save();
            }
        }
    }

    /**
     * Comprobantes ya emitidos: se les asigna retroactivamente el ÚNICO
     * estab/pto_emi que existía en el config global al momento (todo lo
     * histórico se emitió bajo esa única configuración, así que no hay
     * ambigüedad), y el warehouse/store de la venta que originó cada
     * comprobante.
     */
    private function backfillElectronicInvoicesContext(Store $store): void
    {
        $estab = Setting::where('store_id', $store->id)->where('key', 'sri_estab')->value('value');
        $ptoEmi = Setting::where('store_id', $store->id)->where('key', 'sri_pto_emi')->value('value');

        ElectronicInvoice::whereNull('store_id')
            ->with('sale:id,warehouse_id')
            ->chunkById(200, function ($invoices) use ($store, $estab, $ptoEmi) {
                foreach ($invoices as $invoice) {
                    $invoice->store_id = $store->id;
                    $invoice->warehouse_id = $invoice->sale?->warehouse_id;
                    $invoice->estab = $estab;
                    $invoice->pto_emi = $ptoEmi;
                    $invoice->save();
                }
            });
    }

    /**
     * Re-asocia cada asignación de rol existente a la tienda inicial
     * (team_id = store_id, ver config/permission.php). Esto NO activa
     * todavía el modo teams de Spatie (config('permission.teams') sigue
     * en false hasta la Fase 3) -- solo deja el dato ya poblado para
     * cuando se active.
     */
    private function backfillRoleTeams(Store $store): void
    {
        DB::table('roles')->whereNull('store_id')->update(['store_id' => $store->id]);
        DB::table('model_has_roles')->whereNull('store_id')->update(['store_id' => $store->id]);
        DB::table('model_has_permissions')->whereNull('store_id')->update(['store_id' => $store->id]);
    }
}
