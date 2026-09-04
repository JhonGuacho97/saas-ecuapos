<?php

use App\Models\Currency;
use App\Models\ManageStock;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Support\Facades\File;

if (! function_exists('getPageSize')) {
    /**
     * @return mixed
     */
    function getPageSize($request)
    {
        return $request->input('page.size', 10);
    }
}

/**
 * Sin filtrar por store_id, con 2+ tiendas esto devolvía el primer
 * registro key='logo' que encontrara MySQL (cualquiera, sin relación
 * con la tienda activa) -- el logo se guardaba bien (SettingRepository
 * ::updateSettings() ya lo escribe con store_id correcto) pero nunca se
 * mostraba el que acababas de subir. Mismo patrón de
 * getSettingValue(): $storeId explícito para los llamadores sin
 * contexto HTTP (SriRideService, corre en jobs de cola), si no se pasa
 * cae a currentStoreId(); sin ninguna tienda resuelta (pantalla de
 * login antes de autenticarse) se restringe al fallback de sistema
 * (store_id NULL) en vez de dejar la query sin filtrar.
 *
 * A propósito SIN static cache (a diferencia de getSettingValue()):
 * addMedia() puede correr más de una vez sobre este mismo helper dentro
 * del mismo proceso PHP de larga duración (`php artisan serve` es un
 * único proceso, no uno por request -- ver nota de OPcache en
 * ProductKitAPIController/WarehousePriceAPIController de esta misma
 * sesión), y una versión con static cache demostró servir el logo
 * VIEJO después de subir uno nuevo hasta que el proceso se reciclara.
 * El precio es un query extra por request; no vale la pena el riesgo.
 */
function getLogoUrl(?int $storeId = null): string
{
    $storeId = $storeId ?? currentStoreId();

    $query = Setting::where('key', '=', 'logo')->orderByDesc('store_id');
    if ($storeId) {
        $query->where(function ($q) use ($storeId) {
            $q->whereNull('store_id')->orWhere('store_id', $storeId);
        });
    } else {
        $query->whereNull('store_id');
    }
    $appLogo = $query->first();

    return (empty($appLogo) || empty($appLogo->logo)) ? '' : asset($appLogo->logo);
}

if (! function_exists('getSettingValue')) {
    /**
     * @return mixed
     */
    function getSettingValue($keyName)
    {
        $storeId = currentStoreId();
        $key = 'setting'.'-'.($storeId ?? 'global').'-'.$keyName;

        static $settingValues;

        if (isset($settingValues[$key])) {
            return $settingValues[$key];
        }

        // Cada key de settings tiene una fila store_id NULL (fallback de
        // sistema/legacy) y, desde que existe la tienda inicial, una
        // fila store_id=<tienda> (override) -- ver
        // MigrateToInitialStoreSeeder::backfillSettings(). orderByDesc
        // prioriza el override (store_id numérico > NULL) cuando hay
        // contexto de tienda resuelto.
        //
        // Sin contexto (jobs, comandos, rutas públicas como front-setting
        // en la pantalla de login antes de autenticarse), NO se puede
        // dejar la query sin filtrar -- con 2+ tiendas eso deja que
        // orderByDesc('store_id') agarre la fila de la tienda con el ID
        // más alto (cualquiera, sin relación con quién está pidiendo
        // esto), filtrando datos de una tienda ajena hacia un contexto
        // que no tiene ninguna tienda resuelta. Se restringe al fallback
        // de sistema (store_id NULL), el único dato que tiene sentido
        // mostrar sin saber a qué tienda pertenece el pedido.
        $query = Setting::where('key', '=', $keyName);
        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            });
        } else {
            $query->whereNull('store_id');
        }

        /** @var Setting $setting */
        $setting = $query->orderByDesc('store_id')->first();
        $settingValues[$key] = $setting->value;

        return $setting->value;
    }
}

function canDelete(array $models, string $columnName, int $id): bool
{
    foreach ($models as $model) {
        $result = $model::where($columnName, $id)->exists();

        if ($result) {
            return true;
        }
    }

    return false;
}

function getCurrencyCode()
{
    $currencyId = Setting::where('key', '=', 'currency')->first()->value;

    return Currency::whereId($currencyId)->first()->symbol;
}

function getLoginUserLanguage(): string
{
    return \Illuminate\Support\Facades\Auth::user()->language;
}

if (! function_exists('manageStock')) {
    /**
     * @param $request
     * @return mixed
     */
    function manageStock($warehouseID, $productID, $qty = 0)
    {
        // Se usa desde varios repositorios en puntos que no siempre están
        // dentro de una transacción propia (ej. reversiones de stock).
        // DB::transaction() anida con savepoints si ya hay una transacción
        // abierta, y lockForUpdate() evita que dos llamadas concurrentes
        // (ej. dos reversiones al mismo tiempo) lean el mismo valor antes
        // de que ninguna haga commit.
        \Illuminate\Support\Facades\DB::transaction(function () use ($warehouseID, $productID, $qty) {
            // Si $productID es un kit (ej. "Pack Cerveza + Michelada"), no
            // tiene stock propio -- se expande a sus componentes reales
            // antes de tocar manage_stocks, con el signo de $qty
            // preservado (restaurar un kit = restaurar cada componente).
            // Para un producto normal esto devuelve exactamente
            // [[$productID, $qty]], cero cambio de comportamiento.
            $productModel = \App\Models\Product::with('kitItems')->find($productID);
            $movimientos = $productModel
                ? $productModel->resolverMovimientoStock($qty)
                : [['product_id' => $productID, 'quantity' => $qty]];

            foreach ($movimientos as $movimiento) {
                $movProductID = $movimiento['product_id'];
                $movQty = $movimiento['quantity'];

                $product = ManageStock::whereWarehouseId($warehouseID)
                    ->whereProductId($movProductID)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $totalQuantity = $product->quantity + $movQty;

                    if ($totalQuantity < 0) {
                        // Antes esto se fijaba a 0 en silencio -- ej. se
                        // elimina una transferencia después de que parte de
                        // ese stock ya se vendió desde la bodega destino:
                        // manageStock() intentaba restar más de lo que hay
                        // y lo clampeaba a 0 sin avisar, dejando el
                        // inventario mostrando "0" en vez de reflejar el
                        // déficit real (que ya se vendió más de lo que esta
                        // reversión puede devolver). Ahora se bloquea la
                        // operación para que quien la dispara se entere.
                        throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                            "No se puede ajustar el stock del producto #{$movProductID} en la bodega #{$warehouseID}: ".
                            "quedaría en {$totalQuantity} (disponible {$product->quantity}, se intentó aplicar {$movQty})."
                        );
                    }
                    $product->update([
                        'quantity' => $totalQuantity,
                    ]);
                } else {
                    if ($movQty < 0) {
                        // Mismo criterio que arriba: no hay ninguna fila de
                        // stock todavía para este producto/bodega, y se
                        // intenta restar -- no hay "0 disponible - algo"
                        // válido, así que se bloquea en vez de crear la fila
                        // en 0 en silencio.
                        throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                            "No se puede ajustar el stock del producto #{$movProductID} en la bodega #{$warehouseID}: ".
                            "no hay stock registrado ahí (se intentó restar {$movQty})."
                        );
                    }

                    ManageStock::create([
                        'warehouse_id' => $warehouseID,
                        'product_id' => $movProductID,
                        'quantity' => $movQty,
                    ]);
                }
            }

            if ($productModel && $productModel->is_kit) {
                $productModel->syncKitStock($warehouseID);
            }
        });
    }
}

if (! function_exists('keyExist')) {
    function keyExist($key)
    {
        $exists = Setting::where('key', $key)->exists();

        return $exists;
    }
}

function getSupplierGrandTotalFilterIds($search)
{
    $supplierData = Supplier::with('purchases')->get();
    $ids = [];
    foreach ($supplierData as $key => $supplier) {
        $value = $supplier->purchases->sum('grand_total');
        if ($search != '') {
            if ($value == $search) {
                $ids[] = $supplier->id;
            }
        }
    }

    return $ids;
}

if (! function_exists('replaceArrayValue')) {
    function replaceArrayValue(&$array, $key, $replaceValue)
    {
        foreach ($array as $index => $value) {
            if (is_array($value)) {
                $array[$index] = replaceArrayValue($value, $key, $replaceValue);
            }
            if ($index == $key) {
                $array[$index] = $replaceValue;
            }
        }

        return $array;
    }
}

if (! function_exists('getLogo')) {
    function getLogo()
    {
        $logoImage = getLogoUrl();

        $logo = '';
        if ($logoImage && File::exists(asset($logoImage))) {
            $logo = base64_encode(file_get_contents(asset($logoImage)));
        }

        return 'data:image/png;base64,'.$logo;
    }
}

if (! function_exists('currencyAlignment')) {
    function currencyAlignment($amount)
    {
        if (getSettingValue('is_currency_right') != 1) {
            return getCurrencyCode().' '.$amount;
        }

        return $amount.' '.getCurrencyCode();
    }
}

if (! function_exists('currentStoreId')) {
    /**
     * Tienda activa ya resuelta por el middleware ResolveActiveStore (ver
     * app/Http/Middleware/ResolveActiveStore.php). Helper global -- no solo
     * método de AppBaseController -- porque los Repositories (que no
     * extienden AppBaseController) también necesitan asignar store_id al
     * crear filas de catálogo.
     */
    function currentStoreId(): ?int
    {
        return request()?->attributes->get('current_store_id');
    }
}

if (! function_exists('requireCurrentStoreId')) {
    /**
     * Igual que currentStoreId() pero exige que haya una tienda resuelta --
     * usar antes de crear cualquier fila store-scoped (producto, cliente,
     * categoría, etc.), donde un store_id null violaría la constraint
     * NOT NULL en BD con un error confuso.
     */
    function requireCurrentStoreId(): int
    {
        $storeId = currentStoreId();
        if ($storeId === null) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                'No se pudo determinar la tienda activa. Seleccioná una tienda antes de continuar.'
            );
        }

        return $storeId;
    }
}

if (! function_exists('currentOrganizationId')) {
    /** Organización SaaS validada para la petición actual. */
    function currentOrganizationId(): ?int
    {
        return request()?->attributes->get('current_organization_id');
    }
}

if (! function_exists('requireCurrentOrganizationId')) {
    function requireCurrentOrganizationId(): int
    {
        $organizationId = currentOrganizationId();
        if ($organizationId === null) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                'No se pudo determinar la organización activa.'
            );
        }

        return $organizationId;
    }
}
