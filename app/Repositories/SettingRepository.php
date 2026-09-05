<?php

namespace App\Repositories;

use App\DotenvEditor;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SettingRepository
 */
class SettingRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'key',
        'value',
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'key',
        'value',
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
    public function model(): string
    {
        return Setting::class;
    }

    /**
     * @return mixed
     */
public function updateSettings($input)
{
    try {
        DB::beginTransaction();

        // firstOrCreate() sin store_id encontraría/actualizaría la fila
        // NULL de fallback en vez del override de la tienda activa (ver
        // MigrateToInitialStoreSeeder::backfillSettings() -- existen
        // ambas para cada key), dejando el override desactualizado y
        // sin efecto real ya que index()/getFrontSettingsValue() lo
        // prefieren sobre el fallback.
        $storeId = requireCurrentStoreId();

        // Manejo del logo
        if (isset($input['logo']) && !empty($input['logo'])) {
            $setting = Setting::firstOrCreate(['key' => 'logo', 'store_id' => $storeId], ['value' => '']);
            
            $media = $setting->addMedia($input['logo'])
                ->toMediaCollection(Setting::PATH, config('app.media_disc'));
            
            $urlLogo = str_replace('\\', '/', $media->getFullUrl());
            $setting->update(['value' => $urlLogo]);
            $input['logo'] = $setting->getLogoAttribute();
        }

            $settingInputArray = Arr::only($input, [
                'currency',
                'email',
                'company_name',
                'phone',
                'developed',
                'footer',
                'default_language',
                'default_customer',
                'default_warehouse',
                'stripe_key',
                'stripe_secret',
                'sms_gateway',
                'twillo_sid',
                'twillo_token',
                'twillo_from',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_Encryption',
                'address',
                'show_version_on_footer',
                'country',
                'state',
                'city',
                'postcode',
                'date_format',
                'purchase_code',
                'purchase_return_code',
                'sale_code',
                'sale_return_code',
                'expense_code',
                'is_currency_right',
                'show_logo_in_receipt',
                'show_app_name_in_sidebar'
            ]);

        foreach ($settingInputArray as $key => $value) {
            // index() enmascara smtp_password/stripe_secret/twillo_token
            // en la respuesta -- si el formulario los reenvía sin que el
            // usuario los haya tocado, este es exactamente ese valor
            // enmascarado, no la contraseña real. Sin este chequeo se
            // sobreescribiría la credencial real con la máscara literal
            // en cada guardado de Configuración que no toque ese campo.
            if (in_array($key, ['smtp_password', 'stripe_secret', 'twillo_token'])
                && $value === self::MAIL_PASSWORD_MASK) {
                continue;
            }

            $setting = Setting::firstOrCreate(['key' => $key, 'store_id' => $storeId], ['value' => '']);

            // Manejo de campos booleanos
            if (in_array($key, ['show_version_on_footer', 'is_currency_right',
                'show_logo_in_receipt', 'show_app_name_in_sidebar'])) {
                $value = !empty($value);
            }

            if (isset($value)) {
                $setting->update(['value' => $value]);
            }
        }
        
        DB::commit();
        return $input;
        
    } catch (Exception $exception) {
        DB::rollBack();
        throw new UnprocessableEntityHttpException($exception->getMessage());
    }
}
    /**
     * Valor enmascarado que getEnvData() devuelve en vez de la contraseña
     * real -- si vuelve tal cual en el submit, significa que el usuario no
     * la cambió, así que no se debe sobreescribir con eso.
     */
    public const MAIL_PASSWORD_MASK = '••••••••';

    public function updateMailEnvSetting($input)
    {
        $env = new DotenvEditor();
        $inputArr = Arr::except($input, ['_token']);
        $env->setAutoBackup(true);

        $envData = [
            'MAIL_MAILER' => (empty($inputArr['mail_mailer'])) ? '' : $inputArr['mail_mailer'],
            'MAIL_HOST' => (empty($inputArr['mail_host'])) ? '' : $inputArr['mail_host'],
            'MAIL_PORT' => (empty($inputArr['mail_port'])) ? '' : $inputArr['mail_port'],
            'MAIL_USERNAME' => (empty($inputArr['mail_username'])) ? '' : $inputArr['mail_username'],
            'MAIL_FROM_ADDRESS' => (empty($inputArr['mail_from_address'])) ? '' : $inputArr['mail_from_address'],
            'MAIL_ENCRYPTION' => (empty($inputArr['mail_encryption'])) ? '' : $inputArr['mail_encryption'],
        ];

        if (($inputArr['mail_password'] ?? null) !== self::MAIL_PASSWORD_MASK) {
            $envData['MAIL_PASSWORD'] = (empty($inputArr['mail_password'])) ? '' : $inputArr['mail_password'];
        }

        // addData combina claves existentes y nuevas en una sola escritura.
        $env->addData($envData);
    }

    public function createOrUpdateEnv($env, $key, $value): bool
    {
        if (!$env->keyExists($key)) {
            $env->addData([
                $key => $value,
            ]);

            return true;
        }
        $env->changeEnv([
            $key => $value,
        ]);

        return true;
    }

    /**
     * @return mixed
     */
    public function getEnvData()
    {
        $env = new DotenvEditor();
        $key = $env->getContent();
        $data = collect($key)->only([
            'MAIL_MAILER',
            'MAIL_HOST',
            'MAIL_PORT',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_FROM_ADDRESS',
            'MAIL_ENCRYPTION',
        ])->toArray();

        return [
            'mail_mailer' => $data['MAIL_MAILER'],
            'mail_host' => $data['MAIL_HOST'],
            'mail_port' => $data['MAIL_PORT'],
            'mail_username' => $data['MAIL_USERNAME'],
            'mail_password' => empty($data['MAIL_PASSWORD']) ? '' : self::MAIL_PASSWORD_MASK,
            'mail_from_address' => $data['MAIL_FROM_ADDRESS'],
            'mail_encryption' => $data['MAIL_ENCRYPTION'],
        ];
    }
}
