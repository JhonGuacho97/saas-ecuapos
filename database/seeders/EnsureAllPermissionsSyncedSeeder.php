<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Consolida todos los permisos referenciados por las rutas de la API
 * (algunos de los cuales solo se crean vía DatabaseSeeder, que no siempre
 * corre en instalaciones existentes) y vuelve a sincronizar TODOS los
 * permisos existentes al rol admin. Idempotente -- segura de correr
 * varias veces y en cualquier instalación (nueva o existente).
 */
class EnsureAllPermissionsSyncedSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'manage_roles' => 'Manage Roles',
            'manage_brands' => 'Manage Brands',
            'manage_currency' => 'Manage Currency',
            'manage_warehouses' => 'Manage Warehouses',
            'manage_units' => 'Manage Units',
            'manage_product_categories' => 'Manage Product Categories',
            'manage_products' => 'Manage Products',
            'manage_suppliers' => 'Manage Suppliers',
            'manage_customers' => 'Manage Customers',
            'manage_users' => 'Manage Users',
            'change_user_passwords' => 'Cambiar contraseñas de usuarios',
            'change_customer_passwords' => 'Cambiar contraseñas de clientes',
            'manage_expense_categories' => 'Manage Expense Categories',
            'manage_expenses' => 'Manage Expenses',
            'manage_adjustments' => 'Manage Adjustments',
            'manage_transfers' => 'Manage Transfers',
            'manage_setting' => 'Manage Setting',
            'manage_dashboard' => 'Manage Dashboard',
            'manage_pos_screen' => 'Manage Pos Screen',
            'manage_purchase' => 'Manage Purchase',
            'manage_sale' => 'Manage Sale',
            'manage_purchase_return' => 'Manage Purchase Return',
            'manage_sale_return' => 'Manage Sale Return',
            'manage_login_logs' => 'Manage Login Logs',
            'manage_language' => 'Manage Language',
            'manage_stores' => 'Manage Stores',
            'manage_email_templates' => 'Manage Email Templates',
            'manage_reports' => 'Manage Reports',
            'manage_quotations' => 'Manage Quotations',
            'manage_sms_templates' => 'Manage Sms Templates',
            'manage_sms_apis' => 'Manage Sms Apis',
            'manage_electronic_invoices' => 'Manage Electronic Invoices',
            'manage_kardex' => 'Manage Kardex',
            'manage_my-sales' => 'Manage Seller Dashboard',
            'manage_sri_config' => 'Manage SRI Configuration',
            'manage_cash_control' => 'Gestionar control de cajas',
            'view_own_cash_session' => 'Ver mi turno de caja',
            'create_cash_income' => 'Registrar ingreso de caja',
            'create_cash_expense' => 'Registrar egreso de caja',
            'withdraw_cash' => 'Registrar retiro de efectivo',
            'view_cash_supervision' => 'Supervisar turnos y movimientos de cajas abiertas',
            'view_cash_closures' => 'Ver cierres de caja',
            'manage_cash_registers' => 'Gestionar cajas físicas',
            'reverse_cash_movement' => 'Revertir movimiento de caja',
            'transfer_cash' => 'Transferir efectivo entre cajas',
            'review_cash_closure' => 'Revisar cierres de caja',
            'manage_catalog_orders' => 'Manage Catalog Orders',
            'view_accounts_receivable' => 'Ver cuentas por cobrar',
            'collect_accounts_receivable' => 'Registrar cobros de cartera',
            'manage_accounts_receivable' => 'Gestionar condiciones de crédito',
        ];

        foreach ($permissions as $name => $displayName) {
            Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $displayName]
            );
        }

        $adminRoles = Role::whereName(Role::ADMIN)->get();

        // En una base SaaS nueva todavía no existe ningún tenant. El catálogo
        // global de permisos sí debe quedar listo, pero crear aquí un rol
        // admin con store_id NULL hace que Spatie lo trate como rol global y
        // bloquee el primer onboarding al intentar crear admin para su tienda.
        // En una actualización heredada ya habrá una tienda o al menos un
        // usuario, por lo que se conserva el comportamiento anterior.
        if ($adminRoles->isEmpty() && (Store::query()->exists() || User::query()->exists())) {
            $adminRoles = collect([Role::create([
                'name' => 'admin',
                'display_name' => ' Admin',
            ])]);
        }

        $allPermissions = Permission::pluck('name', 'id');
        $adminRoles->each(fn (Role $adminRole) => $adminRole->syncPermissions($allPermissions));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
