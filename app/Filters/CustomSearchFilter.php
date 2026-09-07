<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * Class CustomSearchFilter
 */
class CustomSearchFilter implements Filter
{
    public $searchableFields;

    public function __construct($searchableFields)
    {
        $this->searchableFields = $searchableFields;
        $filterSearchFields = request()->get('filter')['search_fields'] ?? [];
        if (! empty($filterSearchFields)) {
            // El cliente solo puede reducir la búsqueda a columnas que el
            // repositorio declaró como buscables. Aceptar nombres de campo
            // arbitrarios permite consultar columnas internas y provoca
            // errores SQL evitables.
            $requestedFields = array_map('trim', explode(',', $filterSearchFields));
            $this->searchableFields = array_values(array_intersect(
                $requestedFields,
                $this->searchableFields
            ));
        }
    }

    public function __invoke(Builder $query, $value, string $property): Builder
    {
        if (empty($this->searchableFields)) {
            return $query;
        }

        // Las alternativas del buscador deben quedar agrupadas:
        //   tenant_scope AND (name LIKE ... OR email LIKE ...)
        // Antes se añadían como OR al nivel raíz y una coincidencia podía
        // saltarse cualquier where de tienda u organización ya aplicado.
        return $query->where(function (Builder $searchQuery) use ($value): void {
            if (is_array($value)) {
                foreach ($this->searchableFields as $searchableField) {
                    foreach ($value as $string) {
                        $searchQuery->orWhere($searchableField, 'LIKE', '%'.$string.'%');
                    }
                }
                return;
            }

            foreach ($this->searchableFields as $searchableField) {
                $searchQuery->orWhere($searchableField, 'LIKE', '%'.$value.'%');
            }
        });
    }
}
