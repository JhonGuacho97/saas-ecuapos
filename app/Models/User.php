<?php

namespace App\Models;

use App\Models\Contracts\JsonResourceful;
use App\Notifications\ResetPasswordNotification;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

/**
 * App\Models\User
 *
 * @property int $id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $status
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection|\Illuminate\Notifications\DatabaseNotification[] $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Permission[] $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\Spatie\Permission\Models\Role[] $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\Laravel\Sanctum\PersonalAccessToken[] $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory(...$parameters)
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User permission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User role($roles, $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
 * @property-read string $image_url
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection|Media[] $media
 * @property-read int|null $media_count
 * @property string $language
 * @method static \Illuminate\Database\Eloquent\Builder|User whereLanguage($value)
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Sale> $sales
 * @property-read int|null $sales_count
 * @mixin \Eloquent
 */
class User extends Authenticatable implements HasMedia, JsonResourceful, CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, InteractsWithMedia, HasJsonResourcefulData;

    const JSON_API_TYPE = 'users';

    public const PATH = 'user_image';

    protected $appends = ['image_url'];

    // Respaldo para altas realizadas fuera de UserRepository (comandos,
    // integraciones o futuros flujos). UserRepository usa primero la
    // configuración específica de la tienda.
    protected $attributes = [
        'language' => 'sp',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'language',
        'default_warehouse_id',
        'is_super_admin',
    ];

    public static $rules = [
        'first_name' => 'required',
        'last_name' => 'required',
        'email' => 'required|email|unique:users',
        'phone' => 'required|numeric',
        'password' => 'required|min:6',
        'confirm_password' => 'required|min:6|same:password',
        'image' => 'image|mimes:jpg,jpeg,png',
    ];

    public function getImageUrlAttribute(): string
    {
        /** @var Media $media */
        $media = $this->getMedia(User::PATH)->first();
        if (! empty($media)) {
            return $media->getFullUrl();
        }

        return '';
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_super_admin' => 'boolean',
    ];

    public function prepareLinks(): array
    {
        return [
            'self' => route('users.show', $this->id),
        ];
    }

    public function prepareAttributes(): array
    {
        $fields = [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image_url,
            'role' => $this->roles,
            'created_at' => $this->created_at,
            'language' => $this->language,
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_warehouse_name' => $this->defaultWarehouse?->name,
            'store_ids' => $this->stores->pluck('id'),
        ];

        return $fields;
    }

    /**
     * @deprecated Se reemplaza por warehouses() (N-a-N vía user_warehouse)
     * en la Fase 2/3 -- se deja intacto hasta entonces para no romper
     * AppBaseController::restrictedWarehouseId(), que todavía lo usa.
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id', 'id');
    }

    /**
     * A qué tiendas tiene acceso este usuario. Sin ninguna fila = sin
     * acceso a ninguna tienda (a diferencia de warehouses(), acá no hay
     * "ve todo por defecto" -- el acceso a una tienda siempre es
     * explícito). Ver Store::users() para la relación inversa.
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'user_store', 'user_id', 'store_id')
            ->withTimestamps();
    }

    /** Organizaciones SaaS a las que pertenece el usuario. */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Sub-alcance opcional dentro de las tiendas a las que el usuario ya
     * tiene acceso. Sin ninguna fila = ve todas las sucursales de sus
     * tiendas (misma semántica que default_warehouse_id nulo hoy); una o
     * más filas = restringido a esas sucursales exactas.
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouse', 'user_id', 'warehouse_id')
            ->withTimestamps();
    }

    public function sendPasswordResetNotification($token)
    {
        $url = url('/sistema#/reset-password/'.$token);

        $this->notify(new ResetPasswordNotification($url));
    }

    /**
     * "¿Es admin sin restricciones?" -- históricamente varios lugares del
     * código respondían esto con hasRole(Role::ADMIN), es decir "¿el
     * nombre de tu rol es LITERALMENTE la palabra 'admin'?". Eso se rompe
     * apenas alguien crea un segundo rol de nivel administrador con otro
     * nombre (ej. "SUPER_ADMIN") -- ese usuario queda tratado como
     * restringido (limitado a su default_warehouse_id, sin ver otros
     * admins, etc.) aunque conceptualmente tenga el mismo nivel de acceso.
     *
     * Acá se responde por PERMISOS, no por nombre: un usuario es admin sin
     * restricciones si sus roles, en conjunto, cubren TODOS los permisos
     * que existen hoy en el sistema. Coincide exactamente con lo que ya
     * hace EnsureAllPermissionsSyncedSeeder para el rol 'admin' (le
     * sincroniza todos los permisos existentes) -- pero ahora cualquier
     * OTRO rol al que se le den todos los permisos manualmente desde
     * Roles/Permisos queda reconocido igual, sin tener que llamarse
     * "admin" ni tocar código.
     */
    public function isUnrestrictedAdmin(): bool
    {
        $totalPermissions = \Spatie\Permission\Models\Permission::count();
        if ($totalPermissions === 0) {
            return false;
        }

        return $this->getAllPermissions()->count() >= $totalPermissions;
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
