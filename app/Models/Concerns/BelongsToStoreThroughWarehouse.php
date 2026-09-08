<?php

namespace App\Models\Concerns;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Variante de [BelongsToStore] para las tablas transaccionales, que
 * históricamente solo tenían warehouse_id (ver la migración
 * 2026_09_08_100000_denormalize_store_id_on_transactional_tables).
 *
 * Filtra igual por store_id, pero al crear lo deriva de la bodega cuando
 * no hay tienda resuelta en el request -- que es justo el caso de los
 * jobs de cola del SRI y de la sincronización offline, donde la fila
 * nace fuera de un request HTTP pero sí conoce su bodega.
 *
 * A diferencia de BelongsToStore, acá una fila sin tienda es un error y
 * no algo que se deje pasar: store_id null significaría que la venta no
 * pertenece a ningún inquilino, y el scope global la escondería de todas
 * las pantallas sin que nadie se entere. Mejor reventar en el insert.
 */
trait BelongsToStoreThroughWarehouse
{
    public static function bootBelongsToStoreThroughWarehouse(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            $storeId = currentStoreId();
            if ($storeId === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.store_id', $storeId);
        });

        static::creating(function ($model) {
            if ($model->store_id !== null) {
                return;
            }

            // currentStoreId() primero: en un request ya está resuelto y
            // validado por ResolveActiveStore, y la bodega ya se verificó
            // contra esa tienda (authorizeWarehouseBelongsToCurrentStore),
            // así que consultar warehouses de nuevo sería un query de más
            // en el camino caliente.
            $model->store_id = currentStoreId()
                ?? Warehouse::withoutGlobalScope('store')
                    ->whereKey($model->warehouse_id)->value('store_id');

            if ($model->store_id === null) {
                throw new RuntimeException(sprintf(
                    '%s no puede guardarse sin tienda: no hay tienda activa en el request y su bodega (%s) no resuelve ninguna.',
                    class_basename($model),
                    $model->warehouse_id ?? 'null'
                ));
            }
        });
    }

    public function scopeAcrossStores(Builder $query): Builder
    {
        return $query->withoutGlobalScope('store');
    }
}
