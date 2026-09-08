<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saca del docroot los dos archivos privados del módulo SRI.
 *
 * El disco 'local' tiene su root en public_path('uploads'), así que todo
 * lo que se guardaba con Storage::disk('local') quedaba servido por HTTP
 * sin autenticación:
 *
 * - `certificados/cert_*.p12`: la firma electrónica legal del negocio.
 *   Además el nombre salía de uniqid(), que es la marca de tiempo en
 *   hexadecimal -- adivinable. Se mueven Y se renombran con Str::random.
 * - `rides/factura_*.pdf`: los RIDE, con nombre y cédula del cliente,
 *   montos y RUC. El nombre es la clave de acceso, un número de 49
 *   dígitos con estructura conocida, o sea enumerable.
 *
 * Ambos pasan a storage/app/private (disco `saas_private`). La descarga
 * de RIDE ya iba por /api/electronic-invoices/{id}/ride, autenticada, así
 * que no se rompe ninguna ruta pública.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->moverCertificados();
        $this->moverRides();
    }

    /**
     * Sin down(): devolver estos archivos al docroot sería reintroducir a
     * propósito la exposición. Un rollback deja el código anterior
     * leyendo del disco 'local' y sin encontrarlos, que es el modo
     * seguro de fallar.
     */
    public function down(): void
    {
    }

    private function moverCertificados(): void
    {
        $origen = public_path('uploads/certificados');
        $destino = Storage::disk('saas_private');

        foreach (DB::table('settings')->where('key', 'sri_certificado_path')->get() as $fila) {
            $rutaVieja = (string) $fila->value;
            if ($rutaVieja === '') {
                continue;
            }

            $archivoViejo = public_path('uploads/'.ltrim($rutaVieja, '/'));
            if (! File::exists($archivoViejo)) {
                continue;
            }

            $rutaNueva = 'certificados/cert_'.Str::random(40).'.p12';
            $destino->put($rutaNueva, File::get($archivoViejo));

            // El setting recién después de que el archivo está escrito:
            // si el put() falla, la fila sigue apuntando al archivo viejo
            // y la tienda conserva su certificado.
            DB::table('settings')->where('id', $fila->id)->update(['value' => $rutaNueva]);
            File::delete($archivoViejo);
        }

        // Huérfanos: certificados en disco que ya no referencia ningún
        // setting. Se borran igual -- no sirven para nada y siguen
        // descargables mientras existan.
        if (File::isDirectory($origen)) {
            File::deleteDirectory($origen);
        }
    }

    private function moverRides(): void
    {
        $origen = public_path('uploads/rides');
        if (! File::isDirectory($origen)) {
            return;
        }

        $destino = Storage::disk('saas_private');
        foreach (File::files($origen) as $archivo) {
            $destino->put('rides/'.$archivo->getFilename(), File::get($archivo->getPathname()));
        }

        File::deleteDirectory($origen);
    }
};
