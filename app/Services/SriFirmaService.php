<?php

namespace App\Services;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;


class SriFirmaService
{
    private string $certificadoPath;
    private string $certificadoClave;

    /**
     * $storeId ya NO se resuelve en el constructor -- este servicio se
     * inyecta por DI en el método handle() de cada Job (Laravel crea la
     * instancia ANTES de que el Job tenga oportunidad de decirle a qué
     * tienda pertenece la emisión en curso), así que la config del
     * certificado se carga recién al llamar firmar()/verificarCertificado(),
     * con el $storeId que el llamador ya sabe (Sale/ElectronicInvoice/
     * CreditNote -- nunca hace falta adivinarlo acá).
     */
    private function cargarCertificado(?int $storeId): void
    {
        $config = SriConfigService::get($storeId);
        // Misma ruta donde lo dejó SriConfigController: el disco privado
        // (storage/app/private), fuera del docroot. Ver
        // SriConfigController::CERT_DISK.
        $this->certificadoPath = \Illuminate\Support\Facades\Storage::disk(
            \App\Http\Controllers\API\SriConfigController::CERT_DISK
        )->path($config['certificado_path']);
        $this->certificadoClave = $config['certificado_clave'];
    }

    // ───────────────────────────────────────────── 
    // MÉTODO PRINCIPAL — firma el XML con XAdES-BES
    // ─────────────────────────────────────────────

    /**
     * Firma el XML del comprobante.
     *
     * Usa el binario go-xml-signer-cli si está configurado y disponible
     * -- es la implementación verificada end-to-end contra el SRI real
     * (autorizada, sin ningún mensaje de advertencia), a diferencia del
     * armado manual de XAdES-BES en PHP (firmarConPhp) que tenía dos
     * huecos reales: el X509IssuerName incompleto (le faltaba el campo
     * organizationIdentifier del certificado) y una referencia de más a
     * KeyInfo que el SRI no esperaba.
     *
     * Si el binario no está configurado o no existe en este servidor
     * (por ejemplo, en desarrollo local en Windows, donde el binario
     * compilado para Linux no puede correr), cae de vuelta al armado en
     * PHP -- así el sistema sigue funcionando en cualquier entorno.
     */
    public function firmar(string $xmlString, ?int $storeId = null): string
    {
        // Antes verificarCertificado() solo se llamaba desde la pantalla
        // manual de Configuración SRI -- un certificado que vence entre
        // esa revisión y la siguiente emisión real solo se descubría
        // cuando el SRI lo rechazaba, gastando un secuencial real por un
        // problema detectable de antemano.
        $verificacion = $this->verificarCertificado($storeId);
        if (!$verificacion['valido']) {
            throw new \RuntimeException(
                'No se puede firmar: '.($verificacion['mensaje'] ?? 'el certificado de firma electrónica no es válido').
                '. Actualizá el certificado en Configuración > SRI antes de reintentar.'
            );
        }

        $binario = env('SRI_GO_SIGNER_PATH', base_path('bin/go-signer-cli-listo/go-xml-signer-cli'));

        if ($binario && file_exists($binario) && is_executable($binario)) {
            return $this->firmarConGo($xmlString, $binario);
        }

        return $this->firmarConPhp($xmlString);
    }

    private function firmarConGo(string $xmlString, string $binario): string
    {
        if (!file_exists($this->certificadoPath)) {
            throw new \RuntimeException(
                "Certificado no encontrado en: {$this->certificadoPath}"
            );
        }

        $tmpInput = tempnam(sys_get_temp_dir(), 'sri_xml_in_');
        file_put_contents($tmpInput, $xmlString);

        $input = json_encode([
            'cert_path'  => $this->certificadoPath,
            'password'   => $this->certificadoClave,
            'input_path' => $tmpInput,
        ]);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proceso = proc_open($binario, $descriptorSpec, $pipes);

        if (!is_resource($proceso)) {
            @unlink($tmpInput);
            throw new \RuntimeException(
                'No se pudo iniciar el proceso de go-xml-signer-cli.'
            );
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $xmlFirmado = stream_get_contents($pipes[1]);
        $salidaError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $codigoSalida = proc_close($proceso);

        @unlink($tmpInput);

        if ($codigoSalida !== 0 || trim($xmlFirmado) === '') {
            throw new \RuntimeException(
                'go-xml-signer-cli no pudo firmar el comprobante. Detalle: '
                    . trim($salidaError)
            );
        }

        return $xmlFirmado;
    }

    // ─────────────────────────────────────────────
    // RESPALDO — armado manual de XAdES-BES en PHP
    // (se usa solo si el binario de Go no está disponible)
    // ─────────────────────────────────────────────

    private function firmarConPhp(string $xmlString): string
    {
        if (!file_exists($this->certificadoPath)) {
            throw new \RuntimeException(
                "Certificado no encontrado en: {$this->certificadoPath}"
            );
        }

        $certs = $this->leerCertificadoPkcs12();

        $privateKey = $certs['pkey'];
        $publicCert = $certs['cert'];
        $certEncoded = $this->extraerCertBase64($publicCert);
        $certDigest = base64_encode(sha1(base64_decode($certEncoded), true));

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xmlString);
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $dom->documentElement->setIdAttribute('id', true);

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        // Referencia 1: el comprobante completo.
        $dsig->addReference(
            $dom,
            XMLSecurityDSig::SHA1,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature']
        );

        // Forzar el URI del Reference ANTES de firmar
        $refNode = $dsig->sigNode->getElementsByTagNameNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'Reference'
        )->item(0);

        if ($refNode) {
            $refNode->setAttribute('URI', '#comprobante');
        }

        // ── XAdES-BES: las SignedProperties se arman y se agregan como
        // una SEGUNDA referencia ANTES de firmar (no después) -- así la
        // firma sí cubre criptográficamente el bloque con los datos del
        // certificado, que es justo lo que el SRI valida. Antes esto se
        // agregaba después de firmar, sin ninguna referencia que lo
        // cubriera: por eso el SRI marcaba "el certificado firmante no
        // es válido" aunque igual autorizaba el comprobante.
        $qualProps = $this->construirSignedProperties($dom, $certEncoded, $certDigest);
        $signedPropsNode = $qualProps->getElementsByTagNameNS(
            'http://uri.etsi.org/01903/v1.3.2#',
            'SignedProperties'
        )->item(0);
        $signedPropsNode->setIdAttribute('Id', true);

        // Se agrega temporalmente al documento para poder canonicalizarlo
        // y calcular su huella -- se retira de acá y se reubica dentro
        // de la firma recién después de firmar, sin tocar su contenido.
        $dom->documentElement->appendChild($qualProps);

        $dsig->addReference(
            $signedPropsNode,
            XMLSecurityDSig::SHA1,
            null,
            [
                'id_name' => 'Id',
                'overwrite' => false,
            ]
        );

        // La librería arma el URI de esta referencia a partir del
        // Id="SignedPropertiesID" que ya le pusimos al nodo (confirmado
        // contra el código fuente de xmlseclibs), pero no soporta
        // agregar el atributo Type por opciones -- el SRI lo espera, así
        // que se agrega a mano, igual que ya hacíamos con el URI de la
        // primera referencia.
        $signedPropsRefNode = $dsig->sigNode->getElementsByTagNameNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'Reference'
        )->item(1);

        if ($signedPropsRefNode) {
            $signedPropsRefNode->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        }

        // ── XAdES-BES + SRI: además de firmar el comprobante y las
        // SignedProperties, el manual del SRI (Ficha Técnica, numeral
        // 6.5) exige explícitamente que el certificado dentro de
        // "KeyInfo" también quede cubierto por la firma, "con objeto de
        // evitar la posibilidad de sustitución del certificado". Antes
        // el certificado se agregaba recién después de firmar (vía
        // add509Cert), sin ninguna referencia que lo cubriera -- ahora
        // se arma ANTES, se le da un Id, y se agrega como tercera
        // referencia.
        $dsig->add509Cert($publicCert, true, false);

        $keyInfoNode = $dsig->sigNode->getElementsByTagNameNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'KeyInfo'
        )->item(0);
        $keyInfoNode->setAttribute('Id', 'KeyInfoID');
        $keyInfoNode->setIdAttribute('Id', true);

        $dsig->addReference(
            $keyInfoNode,
            XMLSecurityDSig::SHA1,
            null,
            [
                'id_name' => 'Id',
                'overwrite' => false,
            ]
        );

        $objKey = new XMLSecurityKey(
            XMLSecurityKey::RSA_SHA1,
            ['type' => 'private']
        );
        $objKey->loadKey($privateKey);

        $dsig->sign($objKey);

        // El Id="Signature" es necesario para que
        // QualifyingProperties[Target="#Signature"] resuelva contra el
        // nodo correcto -- antes no se seteaba.
        $dsig->sigNode->setAttribute('Id', 'Signature');

        $dsig->appendSignature($dom->documentElement);

        // Retirar el QualifyingProperties de su ubicación temporal y
        // reubicarlo -- sin alterar su contenido -- dentro de
        // ds:Object, adentro de la firma ya calculada.
        $dom->documentElement->removeChild($qualProps);

        $signatureNode = $dom->getElementsByTagNameNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'Signature'
        )->item(0);

        $objectNode = $dom->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:Object'
        );
        $objectNode->appendChild($qualProps);
        $signatureNode->appendChild($objectNode);

        return $dom->saveXML();
    }

    // ─────────────────────────────────────────────
    // CONSTRUIR SignedProperties (XAdES-BES)
    // ─────────────────────────────────────────────

    private function construirSignedProperties(
        \DOMDocument $dom,
        string $certEncoded,
        string $certDigest
    ): \DOMElement {
        // Namespace XAdES
        $xadesNs = 'http://uri.etsi.org/01903/v1.3.2#';
        // UTC explícito -- el sufijo 'Z' declara que la hora ES UTC, así
        // que no puede depender de APP_TIMEZONE (Ecuador/UTC-5). Antes
        // coincidía por accidente porque APP_TIMEZONE era UTC; con la app
        // en hora local esto quedaría mal etiquetado (hora local + 'Z').
        $signingTime = now('UTC')->format('Y-m-d\TH:i:s\Z');

        $qualProps = $dom->createElementNS($xadesNs, 'xades:QualifyingProperties');
        $qualProps->setAttribute('Target', '#Signature');

        $signedProps = $dom->createElementNS($xadesNs, 'xades:SignedProperties');
        $signedProps->setAttribute('Id', 'SignedPropertiesID');

        $signedSigProps = $dom->createElementNS($xadesNs, 'xades:SignedSignatureProperties');

        $signingTimeNode = $dom->createElementNS($xadesNs, 'xades:SigningTime', $signingTime);
        $signedSigProps->appendChild($signingTimeNode);

        $signingCert = $dom->createElementNS($xadesNs, 'xades:SigningCertificate');
        $certNode = $dom->createElementNS($xadesNs, 'xades:Cert');

        $certDigestNode = $dom->createElementNS($xadesNs, 'xades:CertDigest');
        $digestMethod = $dom->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:DigestMethod'
        );
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $digestValue = $dom->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:DigestValue',
            $certDigest
        );

        $certDigestNode->appendChild($digestMethod);
        $certDigestNode->appendChild($digestValue);
        $certNode->appendChild($certDigestNode);

        $issuerSerial = $dom->createElementNS($xadesNs, 'xades:IssuerSerial');
        $certInfo = openssl_x509_parse(
            "-----BEGIN CERTIFICATE-----\n" .
            chunk_split($certEncoded, 64) .
            "-----END CERTIFICATE-----"
        );
        $issuerName = $this->formatearIssuer($certInfo['issuer'] ?? []);
        $serialNumber = $certInfo['serialNumber'] ?? '0';

        $x509IssuerName = $dom->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:X509IssuerName',
            $issuerName
        );
        $x509SerialNumber = $dom->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:X509SerialNumber',
            $serialNumber
        );

        $issuerSerial->appendChild($x509IssuerName);
        $issuerSerial->appendChild($x509SerialNumber);
        $certNode->appendChild($issuerSerial);
        $signingCert->appendChild($certNode);
        $signedSigProps->appendChild($signingCert);

        $signedProps->appendChild($signedSigProps);
        $qualProps->appendChild($signedProps);

        return $qualProps;
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Lee el .p12 y devuelve ['pkey' => ..., 'cert' => ...].
     *
     * Primero intenta con la función nativa de PHP (funciona en la
     * gran mayoría de servidores, incluyendo la mayoría de hosting
     * Linux/cPanel). Si falla -- típico en Windows con OpenSSL 3.x,
     * donde el .p12 usa un cifrado "legacy" que la librería interna de
     * PHP no soporta aunque el binario openssl del sistema sí lo
     * tenga disponible -- recurre al binario de openssl de la línea
     * de comandos, con soporte legacy.
     */
    private function leerCertificadoPkcs12(): array
    {
        return self::leerPkcs12ConFallback($this->certificadoPath, $this->certificadoClave);
    }

    /**
     * Lee un .p12 y devuelve ['pkey' => ..., 'cert' => ...], con el
     * mismo respaldo (nativo de PHP primero, binario de openssl del
     * sistema si falla). Público y estático para que también lo use
     * la pantalla de "subir certificado" (que valida un archivo recién
     * subido, antes de guardarlo, no el que ya está configurado).
     */
    public static function leerPkcs12ConFallback(string $certPath, string $clave): array
    {
        if (!file_exists($certPath)) {
            throw new \RuntimeException(
                "Certificado no encontrado en: {$certPath}"
            );
        }

        $certData = file_get_contents($certPath);
        $certs = [];

        if (openssl_pkcs12_read($certData, $certs, $clave)) {
            return $certs;
        }

        return self::leerPkcs12ConOpensslCli($certPath, $clave);
    }

    private static function leerPkcs12ConOpensslCli(string $certPath, string $clave): array
    {
        $opensslBin = env('SRI_OPENSSL_BINARY', 'openssl');
        // Configurable por .env -- en Windows/XAMPP con OpenSSL 3.x
        // suele hacer falta apuntarle la carpeta donde vive
        // legacy.dll (ej. C:\xampp\php\extras\ssl). En la mayoría de
        // hosting Linux esto no hace falta, se puede dejar vacío.
        $providerPath = env('SRI_OPENSSL_LEGACY_PROVIDER_PATH');

        $tmpPem = tempnam(sys_get_temp_dir(), 'sri_cert_');

        $comando = [$opensslBin, 'pkcs12', '-legacy'];
        if ($providerPath) {
            $comando[] = '-provider-path';
            $comando[] = $providerPath;
        }
        $comando = array_merge($comando, [
            '-in', $certPath,
            '-nodes',
            '-passin', 'stdin',
            '-out', $tmpPem,
        ]);

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin -- la clave se manda por acá, nunca queda en el comando ni es visible en la lista de procesos
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proceso = proc_open($comando, $descriptorSpec, $pipes);

        if (!is_resource($proceso)) {
            @unlink($tmpPem);
            throw new \RuntimeException(
                'No se pudo iniciar el proceso de openssl para leer el certificado.'
            );
        }

        fwrite($pipes[0], $clave . "\n");
        fclose($pipes[0]);

        $salidaError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $codigoSalida = proc_close($proceso);

        if ($codigoSalida !== 0 || !file_exists($tmpPem)) {
            @unlink($tmpPem);
            throw new \RuntimeException(
                'No se pudo leer el certificado .p12 (ni con la librería interna de PHP ni con el binario de openssl del sistema). '
                    . 'Verifica la clave y que "openssl" esté disponible en el servidor (o configura SRI_OPENSSL_BINARY / SRI_OPENSSL_LEGACY_PROVIDER_PATH en tu .env). '
                    . 'Detalle: ' . trim($salidaError)
            );
        }

        $pem = file_get_contents($tmpPem);
        // Se borra de inmediato -- adentro queda la llave privada sin
        // cifrar, no debe quedar en disco ni un segundo más de lo
        // necesario.
        @unlink($tmpPem);

        if (!preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----.*?-----END (?:RSA )?PRIVATE KEY-----/s', $pem, $matchKey)) {
            throw new \RuntimeException('El binario de openssl no devolvió una llave privada legible.');
        }

        if (!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matchCert)) {
            throw new \RuntimeException('El binario de openssl no devolvió un certificado legible.');
        }

        return [
            'pkey' => $matchKey[0],
            'cert' => $matchCert[0],
        ];
    }

    private function extraerCertBase64(string $certPem): string
    {
        $cert = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $certPem);
        return trim($cert);
    }

    private function formatearIssuer(array $issuer): string
    {
        $partes = [];

        $orden = ['CN', 'OU', 'O', 'L', 'ST', 'C'];

        foreach ($orden as $campo) {
            if (isset($issuer[$campo])) {
                $partes[] = "{$campo}={$issuer[$campo]}";
            }
        }

        return implode(', ', $partes);
    }

    // ─────────────────────────────────────────────
    // VERIFICAR QUE EL CERTIFICADO ES VÁLIDO
    // ─────────────────────────────────────────────

    public function verificarCertificado(?int $storeId = null): array
    {
        $this->cargarCertificado($storeId);

        if (!file_exists($this->certificadoPath)) {
            return [
                'valido' => false,
                'mensaje' => 'Archivo .p12 no encontrado',
            ];
        }

        try {
            $certs = $this->leerCertificadoPkcs12();
        } catch (\RuntimeException $e) {
            return [
                'valido' => false,
                'mensaje' => 'Clave incorrecta o archivo .p12 dañado: ' . $e->getMessage(),
            ];
        }

        $certInfo = openssl_x509_parse($certs['cert']);
        $validHasta = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
        $vencido = $certInfo['validTo_time_t'] < time();

        return [
            'valido' => !$vencido,
            'titular' => $certInfo['subject']['CN'] ?? 'Desconocido',
            'valid_hasta' => $validHasta,
            'vencido' => $vencido,
            'mensaje' => $vencido
                ? "Certificado vencido el {$validHasta}"
                : "Certificado válido hasta {$validHasta}",
        ];
    }
}