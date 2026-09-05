<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\SettingResource;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\State;
use App\Models\Warehouse;
use App\Repositories\SettingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Class SettingAPIController
 */
class SettingAPIController extends AppBaseController
{
    /** @var SettingRepository */
    private $settingRepository;

    public function __construct(SettingRepository $productRepository)
    {
        $this->settingRepository = $productRepository;
    }

    /**
     * Fila con store_id NULL = fallback de sistema/legado, fila con
     * store_id = override específico de esa tienda. Se ordena por
     * store_id ASC (MySQL pone NULL primero) para que, si existen ambas
     * para la misma key, el pluck() final se quede con la del override
     * -- entra después y pisa al fallback en el array.
     *
     * getFrontSettingsValue() está deliberadamente fuera del grupo
     * auth:sanctum (se usa en la pantalla de Login antes de tener
     * sesión, ver Login.js/ForgotPassword.js) -- currentStoreId()
     * SIEMPRE es null ahí. Sin este `else`, con 2+ tiendas la query
     * quedaba sin ningún where por store_id: devolvía TODAS las filas
     * de TODAS las tiendas, y el pluck() final se quedaba con lo último
     * en orden ascendente -- la tienda con el id más alto, sin relación
     * alguna con quién visita la pantalla de login. whereNull acá
     * restringe al fallback de sistema, lo único que tiene sentido
     * mostrar sin tienda resuelta.
     */
    private function scopedSettingsQuery()
    {
        $storeId = $this->currentStoreId();
        $query = Setting::query()->orderBy('store_id');
        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            });
        } else {
            $query->whereNull('store_id');
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $settings = $this->scopedSettingsQuery()->get()->pluck('value', 'key')->toArray();

        // Esta ruta es accesible para cualquier usuario autenticado (no
        // solo admin -- ver routes/api.php, 'settings' index queda fuera
        // del grupo manage_setting a propósito para no romper los
        // desplegables públicos del formulario). Antes devolvía en texto
        // plano credenciales reales de Stripe/Twilio/SMTP a cualquiera
        // con sesión iniciada -- mismo problema que ya se corrigió para
        // mail_password, pero estos tres campos quedaron afuera.
        foreach (['smtp_password', 'stripe_secret', 'twillo_token'] as $secretKey) {
            if (!empty($settings[$secretKey])) {
                $settings[$secretKey] = SettingRepository::MAIL_PASSWORD_MASK;
            }
        }

        $settings['logo'] = getLogoUrl();
        $settings = $this->resolveStoreDefaults($settings);
        $settings['currency_symbol'] = Currency::whereId($settings['currency'])->first()->symbol ?? '';
        $settings['countries'] = Country::all();

        return $this->sendResponse(new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data retrieved successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $input = $request->all();
        $settings = $this->settingRepository->updateSettings($input);

        return $this->sendResponse(new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data updated successfully');
    }

    public function clearCache(): JsonResponse
    {
        Artisan::call('cache:clear');

        return $this->sendSuccess(__('messages.success.cache_clear_successfully'));
    }

    public function getFrontSettingsValue(): JsonResponse
    {
        $keyName = [
            'currency', 'email', 'company_name', 'phone', 'developed', 'footer', 'default_language', 'default_customer',
            'default_warehouse', 'address', 'show_app_name_in_sidebar'
        ];
        $settings = $this->scopedSettingsQuery()->whereIn('key', $keyName)->get()->pluck('value', 'key')->toArray();
        $settings['logo'] = getLogoUrl();
        $settings = $this->resolveStoreDefaults($settings);
        $settings['currency_symbol'] = Currency::whereId($settings['currency'])->first()->symbol ?? '';

        return $this->sendResponse(new SettingResource(['type' => 'settings', 'value' => $settings]),
            'Setting value retrieved successfully.');
    }

    private function resolveStoreDefaults(array $settings): array
    {
        $warehouse = $this->defaultWarehouseForCurrentStore();
        $storeId = $this->currentStoreId();
        $customerId = getSettingValue('default_customer');
        $customer = $storeId && $customerId
            ? Customer::where('store_id', $storeId)->whereKey($customerId)->first()
            : null;

        $settings['store_id'] = $storeId;
        $settings['default_warehouse'] = $warehouse?->id;
        $settings['warehouse_name'] = $warehouse?->name ?? '';
        $settings['default_customer'] = $customer?->id;
        $settings['customer_name'] = $customer?->name ?? '';

        return $settings;
    }

    public function getStates($countryId): JsonResponse
    {
        $states = State::whereCountryId($countryId)->pluck('name');

        return $this->sendResponse(new SettingResource(['type' => 'states', 'value' => $states]),
            'States retrieved successfully.');
    }

    public function getMailSettings()
    {
        $envData = $this->settingRepository->getEnvData();

        return $this->sendResponse($envData, 'Mail Credential Retrieved Successfully');
    }

    public function updateMailSettings(Request $request): JsonResponse
    {
        $request->validate([
            'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from_address', 'mail_encryption',
        ]);
        $this->settingRepository->updateMailEnvSetting($request->all());

        Artisan::call('optimize:clear');
        Artisan::call('config:cache');

        return $this->sendSuccess('Mail Settings Save Successfully');
    }
}
