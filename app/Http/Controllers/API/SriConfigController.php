<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\Setting;
use App\Services\SriService;
use App\Services\SriSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class SriConfigController extends AppBaseController
{
    /**
     * El .p12 es la firma electrónica legal del negocio. Vivía en el disco
     * 'local', cuyo root es public_path('uploads') -- es decir, dentro del
     * docroot y descargable por HTTP sin autenticación. Va al disco privado
     * (storage/app/private), el mismo que usan los comprobantes de pago.
     *
     * Si algún día se cambia este disco, hay que mover también los archivos
     * ya subidos: ver la migración
     * 2026_09_08_110000_move_sri_private_files_out_of_the_webroot.
     */
    public const CERT_DISK = 'saas_private';

    public function __construct(
        protected SriService $sriService,
        protected SriSequenceService $sequenceService
    ) {}

    /**
     * Mismo criterio que SettingAPIController::scopedSettingsQuery() --
     * fila store_id NULL = fallback de sistema/legado, fila
     * store_id=<tienda> = override específico de esa tienda. Cada
     * tienda necesita poder tener su propio RUC/certificado/estab
     * (son entidades fiscales potencialmente distintas ante el SRI),
     * así que la config SRI sigue exactamente el mismo patrón que el
     * resto de Settings en vez de vivir en una única fila global
     * compartida por todas las tiendas.
     */
    /**
     * Sin tienda resuelta (2+ tiendas del admin sin X-Store-Id todavía --
     * ver el mismo hueco encontrado en SettingAPIController), no se
     * puede dejar la query sin filtrar: con 2+ tiendas eso devolvía
     * filas de TODAS, y el consumidor (get()->pluck() o ->value() según
     * el caso) terminaba quedándose con la de la tienda con el ID más
     * alto -- cualquiera, sin relación con la tienda que el admin
     * realmente tiene activa. whereNull restringe al fallback de
     * sistema, que para SRI en la práctica no debería usarse nunca
     * (cada tienda necesita su propio RUC/certificado) pero es preferible
     * a filtrar el de una tienda ajena.
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

    /**
     * Lee UNA key -- a diferencia de scopedSettingsQuery(), que hay que
     * consumir con get()->pluck() para que el override le gane al
     * fallback (orderBy ascendente pone NULL primero), acá directamente
     * se ordena descendente y se toma la primera fila: si existe una
     * fila de la tienda activa, MySQL la devuelve antes que la NULL
     * (NULL siempre ordena como la más chica, con ASC o DESC), así que
     * ->value() ya trae lo correcto sin tener que traer ambas filas.
     */
    private function scopedSettingValue(string $key): ?string
    {
        $storeId = $this->currentStoreId();
        $query = Setting::query()->where('key', $key)->orderByDesc('store_id');
        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            });
        } else {
            $query->whereNull('store_id');
        }

        return $query->value('value');
    }

    // ── Obtener configuración actual ──────────────────────────────────────

    public function index(): JsonResponse
    {
        $keys = [
            'sri_ruc',
            'sri_razon_social',
            'sri_nombre_comercial',
            'sri_dir_matriz',
            'sri_estab',
            'sri_pto_emi',
            'sri_ambiente',
            'sri_obligado_contabilidad',
            'sri_regimen_rimpe',
            'sri_certificado_path',
            'sri_logo',
        ];

        $settings = $this->scopedSettingsQuery()
            ->whereIn('key', $keys)
            ->get()
            ->pluck('value', 'key')
            ->toArray();

        // Verificar si el certificado existe y está vigente
        $certInfo = null;
        if (!empty($settings['sri_certificado_path'])) {
            $certInfo = $this->obtenerInfoCertificado(
                $settings['sri_certificado_path']
            );
        }

        return $this->sendResponse([
            'config' => $settings,
            'cert_info' => $certInfo,
        ], 'Configuración SRI obtenida correctamente.');
    }

    // ── Subir y validar el certificado .p12 ──────────────────────────────

    // public function subirCertificado(Request $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'certificado' => 'required|file|mimes:p12,pfx|max:2048',
    //         'clave'       => 'required|string',
    //     ], [
    //         'certificado.mimes' => 'El archivo debe ser un certificado .p12 o .pfx',
    //         'certificado.max'   => 'El certificado no debe superar 2MB',
    //         'clave.required'    => 'La clave del certificado es obligatoria',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $validator->errors()->first(),
    //         ], 422);
    //     }

    //     $archivo = $request->file('certificado');
    //     $clave   = $request->input('clave');

    //     // Leer y validar el .p12 con la clave proporcionada
    //     $certData = file_get_contents($archivo->getRealPath());
    //     $certs    = [];

    //     if (!openssl_pkcs12_read($certData, $certs, $clave)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'La clave del certificado es incorrecta o el archivo está dañado.',
    //         ], 422);
    //     }

    //     // Extraer información del certificado
    //     $certInfo = openssl_x509_parse($certs['cert']);
    //     $vencido  = $certInfo['validTo_time_t'] < time();

    //     if ($vencido) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'El certificado está vencido desde ' .
    //                 date('d/m/Y', $certInfo['validTo_time_t']) . '.',
    //         ], 422);
    //     }

    //     // Guardar el archivo en storage (reemplaza el anterior si existe)
    //     $rutaAnterior = Setting::where('key', 'sri_certificado_path')->value('value');
    //     if ($rutaAnterior && Storage::disk('local')->exists($rutaAnterior)) {
    //         Storage::disk('local')->delete($rutaAnterior);
    //     }

    //     $nombreArchivo = 'certificados/' . uniqid('cert_') . '.p12';
    //     Storage::disk('local')->put($nombreArchivo, $certData);

    //     // Extraer datos del certificado para autocompletar el formulario
    //     $subject = $certInfo['subject'] ?? [];
    //     $ruc     = $this->extraerRuc($certInfo);

    //     // Guardar clave y ruta en settings
    //     $this->guardarSetting('sri_certificado_path', $nombreArchivo);
    //     $this->guardarSetting('sri_certificado_clave', $clave);

    //     return $this->sendResponse([
    //         'titular'      => $certInfo['subject']['CN'] ?? 'Desconocido',
    //         'valid_hasta'  => date('d/m/Y H:i', $certInfo['validTo_time_t']),
    //         'vencido'      => false,
    //         'ruc_detectado' => $ruc,
    //         'razon_social'  => $certInfo['subject']['CN'] ?? '',
    //         'cert_path'    => $nombreArchivo,
    //     ], 'Certificado subido y validado correctamente.');
    // }

    public function subirCertificado(Request $request): JsonResponse
    {
        // Falla rápido y claro -- sin tienda activa resuelta no hay a
        // quién guardarle este certificado (ver guardarSetting()); mejor
        // no gastar tiempo leyendo/validando el archivo subido si de
        // todas formas el guardado final va a rechazar la operación.
        requireCurrentStoreId();

        $validator = Validator::make($request->all(), [
            'certificado' => 'required|file|max:2048',
            'clave' => 'required|string',
        ], [
            'certificado.required' => 'Debe seleccionar un certificado.',
            'certificado.file' => 'El archivo enviado no es válido.',
            'certificado.max' => 'El certificado no debe superar 2 MB.',
            'clave.required' => 'La clave del certificado es obligatoria.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $archivo = $request->file('certificado');
        $clave = $request->input('clave');

        // Validar extensión
        $extension = strtolower($archivo->getClientOriginalExtension());

        if (!in_array($extension, ['p12', 'pfx'])) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo debe ser un certificado .p12 o .pfx.',
            ], 422);
        }

        // Leer el archivo
        try {
            $certs = \App\Services\SriFirmaService::leerPkcs12ConFallback(
                $archivo->getRealPath(),
                $clave
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'La clave del certificado es incorrecta o el archivo está dañado.',
            ], 422);
        }

        $certData = file_get_contents($archivo->getRealPath());

        // Extraer información del certificado
        $certInfo = openssl_x509_parse($certs['cert']);

        if (!$certInfo) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible leer la información del certificado.',
            ], 422);
        }

        // Verificar vencimiento
        if (($certInfo['validTo_time_t'] ?? 0) < time()) {
            return response()->json([
                'success' => false,
                'message' => 'El certificado está vencido desde ' .
                    date('d/m/Y', $certInfo['validTo_time_t']) . '.',
            ], 422);
        }

        // Eliminar certificado anterior (de ESTA tienda -- ver
        // scopedSettingValue(); sin esto se podía borrar o dejar
        // huérfano el certificado de otra tienda).
        $rutaAnterior = $this->scopedSettingValue('sri_certificado_path');

        if ($rutaAnterior && Storage::disk(self::CERT_DISK)->exists($rutaAnterior)) {
            Storage::disk(self::CERT_DISK)->delete($rutaAnterior);
        }

        // Nombre aleatorio, no uniqid(): uniqid() es la marca de tiempo en
        // hexadecimal, o sea adivinable para quien sepa aproximadamente
        // cuándo se subió el certificado. Str::random(40) no se enumera.
        $nombreArchivo = 'certificados/cert_'.Str::random(40).'.p12';
        Storage::disk(self::CERT_DISK)->put($nombreArchivo, $certData);

        // Extraer datos
        $ruc = $this->extraerRuc($certInfo);

        // El certificado NUNCA trae nombre comercial ni dirección de
        // matriz -- esos son datos del registro de RUC, no del
        // certificado en sí. Se consultan al SRI (mismo servicio que
        // ya se usa para validar identificación de clientes) para
        // traerlos reales. Si la consulta falla (sin internet, RUC
        // recién habilitado, etc.), no bloqueamos la subida del
        // certificado -- solo esos campos quedan sin autocompletar.
        $razonSocial = $certInfo['subject']['CN'] ?? '';
        $direccionMatriz = null;
        $datosSriDisponibles = false;

        if ($ruc) {
            try {
                $datosRuc = $this->sriService->searchByIdentificationSRI($ruc);
                $razonSocial = $datosRuc['name'] ?? $razonSocial;
                $direccionMatriz = $datosRuc['address'] ?? null;
                $datosSriDisponibles = true;
            } catch (\Throwable $e) {
                // Seguimos sin esos datos, no es motivo para rechazar
                // el certificado -- el usuario los puede llenar a mano.
                // (Por ejemplo, si el servicio de consulta de RUC se
                // quedó sin créditos -- eso no debe frenar nunca la
                // subida del certificado.)
            }
        }

        // Guardar configuración
        $this->guardarSetting('sri_certificado_path', $nombreArchivo);
        $this->guardarSetting(
            'sri_certificado_clave',
            Crypt::encryptString($clave)
        );

        return $this->sendResponse([
            'titular' => $certInfo['subject']['CN'] ?? 'Desconocido',
            'valid_hasta' => date('d/m/Y H:i', $certInfo['validTo_time_t']),
            'vencido' => false,
            'ruc_detectado' => $ruc,
            'datos_sri_disponibles' => $datosSriDisponibles,
            'razon_social' => $razonSocial,
            'dir_matriz' => $direccionMatriz,
            'cert_path' => $nombreArchivo,
        ], 'Certificado subido y validado correctamente.');
    }

    // ── Logo del RIDE ──────────────────────────────────────────────────────

    /**
     * Se guarda como una key más de Setting ('sri_logo'), igual que el
     * logo general de la app ('logo' en SettingRepository::updateSettings())
     * -- el archivo va a Spatie MediaLibrary y la URL pública queda
     * cacheada en la columna `value` de la misma fila, así que
     * index()/SriConfigService::get() lo leen sin ninguna query extra,
     * igual que cualquier otro campo de texto de esta config.
     * clearMediaCollection() antes de adjuntar el nuevo archivo evita
     * que el logo anterior quede huérfano en storage (a diferencia del
     * logo general, que sí acumula versiones viejas sin limpiarlas).
     */
    public function subirLogo(Request $request): JsonResponse
    {
        $storeId = requireCurrentStoreId();

        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ], [
            'logo.required' => 'Debes seleccionar una imagen.',
            'logo.image' => 'El archivo debe ser una imagen.',
            'logo.mimes' => 'El logo debe ser PNG o JPG.',
            'logo.max' => 'El logo no debe superar 2 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $setting = Setting::firstOrCreate(['key' => 'sri_logo', 'store_id' => $storeId], ['value' => '']);
        $setting->clearMediaCollection(Setting::PATH);
        $media = $setting->addMedia($request->file('logo'))
            ->toMediaCollection(Setting::PATH, config('app.media_disc'));
        $urlLogo = str_replace('\\', '/', $media->getFullUrl());
        $setting->update(['value' => $urlLogo]);

        return $this->sendResponse([
            'logo_url' => $urlLogo,
        ], 'Logo actualizado correctamente.');
    }

    public function eliminarLogo(): JsonResponse
    {
        $storeId = requireCurrentStoreId();
        $setting = Setting::where('key', 'sri_logo')->where('store_id', $storeId)->first();

        if ($setting) {
            $setting->clearMediaCollection(Setting::PATH);
            $setting->update(['value' => '']);
        }

        return $this->sendResponse([], 'Logo eliminado correctamente.');
    }

    // ── Guardar configuración SRI ─────────────────────────────────────────

    public function guardarConfig(Request $request): JsonResponse
    {
        // Mismo criterio que subirCertificado() -- sin tienda activa
        // resuelta no hay a quién guardarle esta config.
        requireCurrentStoreId();

        $validator = Validator::make($request->all(), [
            'sri_ruc' => 'required|digits:13',
            'sri_razon_social' => 'required|string|max:300',
            'sri_nombre_comercial' => 'nullable|string|max:300',
            'sri_dir_matriz' => 'required|string|max:300',
            'sri_estab' => 'required|digits:3',
            'sri_pto_emi' => 'required|digits:3',
            'sri_ambiente' => 'required|in:1,2',
            'sri_obligado_contabilidad' => 'required|in:SI,NO',
            'sri_regimen_rimpe' => 'nullable|in:,EMPRENDEDOR,NEGOCIO_POPULAR',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $campos = [
            'sri_ruc',
            'sri_razon_social',
            'sri_nombre_comercial',
            'sri_dir_matriz',
            'sri_estab',
            'sri_pto_emi',
            'sri_ambiente',
            'sri_obligado_contabilidad',
            'sri_regimen_rimpe',
        ];

        foreach ($campos as $campo) {
            $this->guardarSetting($campo, $request->input($campo, ''));
        }

        // Limpiar caché de config para que config/sri.php tome los nuevos valores
        \Illuminate\Support\Facades\Artisan::call('config:clear');

        return $this->sendResponse([], 'Configuración SRI guardada correctamente.');
    }

    public function sequences(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ambiente' => 'required|integer|in:1,2',
            'estab' => 'required|digits:3',
            'pto_emi' => 'required|digits:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $sequences = $this->sequenceService->listForSeries(
            $this->requireCurrentStoreId(),
            (int) $request->input('ambiente'),
            (string) $request->input('estab'),
            (string) $request->input('pto_emi')
        );

        return $this->sendResponse($sequences, 'Numeración SRI obtenida correctamente.');
    }

    public function updateSequence(Request $request, string $documentType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ambiente' => 'required|integer|in:1,2',
            'estab' => 'required|digits:3',
            'pto_emi' => 'required|digits:3',
            'ultimo_secuencial' => 'required|integer|min:0|max:999999998',
            'motivo' => 'required|string|min:10|max:500',
            'confirmado' => 'accepted',
        ], [
            'motivo.min' => 'Explica brevemente por qué necesitas ajustar la numeración.',
            'confirmado.accepted' => 'Debes confirmar que verificaste el último secuencial utilizado.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! array_key_exists($documentType, SriSequenceService::DOCUMENT_TYPES)) {
            return response()->json([
                'success' => false,
                'message' => 'El tipo de comprobante no admite control de numeración.',
            ], 422);
        }

        $sequence = $this->sequenceService->adjustLastUsed(
            $this->requireCurrentStoreId(),
            (int) $request->input('ambiente'),
            (string) $request->input('estab'),
            (string) $request->input('pto_emi'),
            $documentType,
            (int) $request->input('ultimo_secuencial'),
            trim((string) $request->input('motivo')),
            auth()->id(),
            $request->ip(),
            $request->userAgent()
        );

        return $this->sendResponse(
            $sequence,
            'Numeración SRI actualizada. El próximo comprobante utilizará ' . $sequence['proximo_formateado'] . '.'
        );
    }

    // ── Verificar estado del certificado actual ───────────────────────────

    public function verificarCertificado(): JsonResponse
    {
        $certPath = $this->scopedSettingValue('sri_certificado_path');
        $certClaveRaw = $this->scopedSettingValue('sri_certificado_clave');
        $certClave = $certClaveRaw ? Crypt::decryptString($certClaveRaw) : null;

        if (!$certPath || !Storage::disk(self::CERT_DISK)->exists($certPath)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay certificado configurado.',
            ], 404);
        }

        $info = $this->obtenerInfoCertificado($certPath, $certClave);

        return $this->sendResponse($info, 'Estado del certificado obtenido.');
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * requireCurrentStoreId() -- igual que SettingRepository::updateSettings()
     * -- porque un updateOrCreate() sin store_id encontraría/actualizaría
     * la fila NULL de fallback en vez del override de la tienda activa,
     * dejando la config recién guardada sin efecto real (index()/
     * SriConfigService::get() prefieren el override sobre el fallback).
     * Si no hay tienda activa resuelta (0/2+ tiendas sin header
     * X-Store-Id), no hay forma segura de saber a cuál guardarle esto --
     * mejor un 422 explícito que corromper la fila global compartida.
     */
    private function guardarSetting(string $key, string $value): void
    {
        $storeId = requireCurrentStoreId();
        Setting::updateOrCreate(['key' => $key, 'store_id' => $storeId], ['value' => $value]);
    }

    private function obtenerInfoCertificado(
        string $certPath,
        ?string $certClave = null
    ): array {
        $fullPath = Storage::disk(self::CERT_DISK)->path($certPath);

        if (!file_exists($fullPath)) {
            return ['valido' => false, 'mensaje' => 'Archivo no encontrado'];
        }

        // if (!$certClave) {
        //     $certClave = Setting::where('key', 'sri_certificado_clave')->value('value');
        // }

        if (!$certClave) {
            $certClaveRaw = $this->scopedSettingValue('sri_certificado_clave');
            $certClave = $certClaveRaw ? Crypt::decryptString($certClaveRaw) : null;
        }

        $certData = file_get_contents($fullPath);
        $certs = [];

        if (!openssl_pkcs12_read($certData, $certs, $certClave)) {
            return ['valido' => false, 'mensaje' => 'No se pudo leer el certificado'];
        }

        $certInfo = openssl_x509_parse($certs['cert']);
        $vencido = $certInfo['validTo_time_t'] < time();

        return [
            'valido' => !$vencido,
            'titular' => $certInfo['subject']['CN'] ?? 'Desconocido',
            'valid_hasta' => date('d/m/Y H:i', $certInfo['validTo_time_t']),
            'vencido' => $vencido,
            'mensaje' => $vencido
                ? 'Certificado vencido el ' . date('d/m/Y', $certInfo['validTo_time_t'])
                : 'Certificado válido hasta ' . date('d/m/Y', $certInfo['validTo_time_t']),
        ];
    }

    private function extraerRuc(array $certInfo): string
    {
        // Uanataca y Security Data guardan el RUC en distintos campos
        // según el emisor y el tipo de certificado.
        $subject = $certInfo['subject'] ?? [];

        // organizationIdentifier: TINEC-XXXXXXXXX001 -- es donde
        // UANATACA guarda el RUC real en certificados de persona
        // natural. OJO: "serialNumber" en estos certificados suele
        // traer la CÉDULA (10 dígitos), no el RUC -- por eso este
        // campo se revisa primero.
        if (isset($subject['organizationIdentifier'])) {
            if (preg_match('/(\d{13})/', $subject['organizationIdentifier'], $matches)) {
                return $matches[1];
            }
        }

        // TINEC-XXXXXXXXX001 (patrón Uanataca, otros tipos de certificado)
        if (isset($subject['serialNumber'])) {
            $serial = $subject['serialNumber'];
            if (preg_match('/(\d{13})/', $serial, $matches)) {
                return $matches[1];
            }
        }

        // Algunos certificados tienen el RUC en OU
        if (isset($subject['OU'])) {
            if (preg_match('/(\d{13})/', $subject['OU'], $matches)) {
                return $matches[1];
            }
        }

        // Último respaldo: recorrer TODOS los valores del subject por
        // si el campo organizationIdentifier vino con otro nombre de
        // clave (ej. el OID crudo "2.5.4.97") según la versión de
        // OpenSSL del servidor -- buscamos 13 dígitos que empiecen
        // igual que la cédula/RUC ya detectados en otros campos, para
        // no confundirnos con otro número de 13 dígitos que no sea el
        // RUC.
        $cedula = null;
        if (isset($subject['serialNumber']) && preg_match('/(\d{10})/', $subject['serialNumber'], $m)) {
            $cedula = $m[1];
        }
        foreach ($subject as $valor) {
            if (!is_string($valor)) {
                continue;
            }
            if (preg_match('/(\d{13})/', $valor, $matches)) {
                if (!$cedula || str_starts_with($matches[1], $cedula)) {
                    return $matches[1];
                }
            }
        }

        return '';
    }
}
