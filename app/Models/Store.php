<?php

namespace App\Models;

use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entidad raíz del modelo multitienda -- un negocio independiente
 * (ej. "General Store", "Fruite Warehouse"). Ver el documento de
 * planificación multitienda para la justificación de por qué es
 * Store -> Warehouse (2 niveles) y no una capa "Branch" intermedia.
 *
 * Los datos fiscales/de empresa (RUC, razón social, certificado SRI,
 * etc.) NO viven acá -- siguen en `settings`, ahora con store_id (ver
 * Setting::forStore()), porque esa tabla ya es el mecanismo key-value
 * que usa todo el código de facturación electrónica existente.
 */
class Store extends BaseModel
{
    use HasFactory, HasJsonResourcefulData;

    protected $table = 'stores';

    const JSON_API_TYPE = 'stores';

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public static $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:255|unique:stores',
    ];

    public function prepareLinks(): array
    {
        return [
            'self' => route('stores.show', $this->id),
        ];
    }

    public function prepareAttributes(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at,
            'users_count' => $this->users_count ?? null,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'store_id', 'id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'store_id', 'id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'store_id', 'id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'store_id', 'id');
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'store_id', 'id');
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class, 'store_id', 'id');
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'store_id', 'id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class, 'store_id', 'id');
    }

    public function catalogSetting(): HasOne
    {
        return $this->hasOne(CatalogSetting::class);
    }

    public function catalogOrders(): HasMany
    {
        return $this->hasMany(CatalogOrder::class);
    }

    /**
     * Usuarios con acceso a esta tienda (user_store) -- distinto de qué
     * ROL tienen en ella, que Spatie resuelve aparte vía model_has_roles
     * + store_id (teams). Ver ADR en el documento de planificación,
     * sección 10.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_store', 'store_id', 'user_id')
            ->withTimestamps();
    }
}
