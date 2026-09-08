<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use RuntimeException;

/**
 * Aísla modelos hijo que no tienen store_id propio usando la relación
 * obligatoria con su documento padre (venta, compra, ajuste, etc.).
 *
 * El padre ya aplica su scope global por tienda. Por eso whereHas() hereda
 * ese límite sin duplicar store_id en cada tabla de detalle. En creación se
 * valida además que el ID padre sea visible en la tienda activa, cerrando la
 * posibilidad de insertar un detalle sobre un documento de otro tenant.
 */
trait BelongsToStoreThroughParent
{
    abstract protected function storeParentRelationName(): string;

    public static function bootBelongsToStoreThroughParent(): void
    {
        static::addGlobalScope('store_parent', function (Builder $builder) {
            if (currentStoreId() === null) {
                return;
            }

            $builder->whereHas((new static())->storeParentRelationName());
        });

        static::creating(function ($model) {
            if (currentStoreId() === null) {
                return;
            }

            $relationName = $model->storeParentRelationName();
            $relation = $model->{$relationName}();
            if (! $relation instanceof BelongsTo) {
                throw new LogicException(sprintf(
                    '%s::%s debe ser una relación BelongsTo.',
                    class_basename($model),
                    $relationName
                ));
            }

            $parentId = $model->getAttribute($relation->getForeignKeyName());
            if ($parentId === null) {
                return;
            }

            $parentExistsInStore = $relation->getRelated()->newQuery()
                ->where($relation->getOwnerKeyName(), $parentId)
                ->exists();

            if (! $parentExistsInStore) {
                throw new RuntimeException(sprintf(
                    '%s no puede vincularse con %s %s fuera de la tienda activa.',
                    class_basename($model),
                    class_basename($relation->getRelated()),
                    $parentId
                ));
            }
        });
    }

    public function scopeAcrossStores(Builder $query): Builder
    {
        return $query->withoutGlobalScope('store_parent');
    }
}
