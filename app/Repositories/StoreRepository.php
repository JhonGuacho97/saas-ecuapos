<?php

namespace App\Repositories;

use App\Models\Store;
use Illuminate\Support\Str;

/**
 * Class StoreRepository
 */
class StoreRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name',
        'slug',
        'created_at',
    ];

    /**
     * Return searchable fields
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Store::class;
    }

    /**
     * El formulario solo pide "Nombre" (ver captura de referencia) -- el
     * slug se genera solo, con el mismo criterio de desambiguación que
     * ya usa MigrateToInitialStoreSeeder::resolveInitialStore().
     */
    public function storeStore($input)
    {
        $input['organization_id'] = requireCurrentOrganizationId();
        $input['slug'] = $this->uniqueSlug($input['name']);
        $input['is_active'] = $input['is_active'] ?? true;

        return $this->create($input);
    }

    private function uniqueSlug(string $name): string
    {
        $slug = Str::slug($name) ?: 'tienda';
        $original = $slug;
        $suffix = 1;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $original.'-'.(++$suffix);
        }

        return $slug;
    }
}
