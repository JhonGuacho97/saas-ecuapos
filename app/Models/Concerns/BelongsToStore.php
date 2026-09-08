<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Aislamiento por tienda automático, en vez de manual.
 *
 * Hasta acá el filtrado por tienda se escribía a mano en cada consulta
 * (scopeQueryToCurrentStore(), authorizeStoreOwnership(), etc.): ~193
 * llamadas repartidas en 68 archivos. Eso funciona mientras nadie
 * olvide una -- y el día que alguien agregue un endpoint sin acordarse,
 * el bug no explota, simplemente devuelve datos de otro negocio.
 *
 * Este trait invierte la carga: el modelo filtra SIEMPRE por la tienda
 * activa, y quien necesite cruzar tiendas tiene que pedirlo explícito
 * con acrossStores(). Olvidarse ahora es seguro; lo peligroso pasó a
 * ser lo que se escribe a propósito, que es justo lo revisable.
 *
 * Dos reglas que definen el comportamiento:
 *
 * 1. Sin tienda resuelta (jobs de cola, comandos de consola, onboarding
 *    antes de que exista la tienda, endpoints de super admin) el scope
 *    NO filtra. Esos contextos ya resuelven su propio alcance y no
 *    tienen de dónde sacar un store_id; filtrar por null los rompería
 *    a todos. Este trait protege el request HTTP de un usuario de
 *    tienda, que es donde vive el riesgo de fuga entre inquilinos.
 *
 * 2. Al crear se rellena store_id con la tienda activa si viene vacío.
 *    Evita la otra mitad del problema: filas que nacen en el inquilino
 *    equivocado (o con store_id null) porque el controlador se olvidó
 *    de setearlo.
 *
 * NO aplicar a Setting: sus filas de fallback de sistema viven con
 * store_id NULL y un `where store_id = X` las dejaría fuera, rompiendo
 * getSettingValue(). Tampoco a Role, que usa el sistema de teams de
 * Spatie y trae su propio filtrado por store_id.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            $storeId = currentStoreId();
            if ($storeId === null) {
                return;
            }

            // Calificado con la tabla: sin esto, cualquier consulta con
            // join contra otra tabla que también tenga store_id revienta
            // con "column store_id is ambiguous".
            $builder->where($builder->getModel()->getTable().'.store_id', $storeId);
        });

        static::creating(function ($model) {
            if ($model->store_id === null && ($storeId = currentStoreId()) !== null) {
                $model->store_id = $storeId;
            }
        });
    }

    /**
     * Escapa del aislamiento a propósito. Reservado para super admin,
     * reportes de plataforma y mantenimiento -- si aparece en un
     * controlador de tienda, es un bug.
     */
    public function scopeAcrossStores(Builder $query): Builder
    {
        return $query->withoutGlobalScope('store');
    }
}
