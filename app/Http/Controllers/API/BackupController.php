<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class BackupController extends AppBaseController
{
    /**
     * Genera un dump SQL de la base de datos y lo descarga.
     * Solo accesible por administradores.
     */
    public function download()
    {
        try {
            $database = config('database.connections.mysql.database');
            $username = config('database.connections.mysql.username');
            $password = config('database.connections.mysql.password');
            $host     = config('database.connections.mysql.host');
            $port     = config('database.connections.mysql.port', 3306);

            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $filename  = "backup_{$database}_{$timestamp}.sql";
            $filepath  = storage_path("app/backups/{$filename}");

            // Crear carpeta si no existe
            if (!file_exists(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            // Construir comando mysqldump
            // --no-tablespaces evita errores de permisos en hosting compartido
            //
            // La contraseña va por MYSQL_PWD y NO como --password=: los
            // argumentos de un proceso son legibles con `ps aux` para
            // cualquier usuario de la máquina mientras el dump corre.
            $command = sprintf(
                'mysqldump --no-tablespaces --host=%s --port=%s --user=%s %s > %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($filepath)
            );

            $previousPassword = getenv('MYSQL_PWD');
            putenv('MYSQL_PWD='.$password);
            try {
                exec($command, $output, $returnCode);
            } finally {
                $previousPassword === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD='.$previousPassword);
            }

            if ($returnCode !== 0) {
                // Intentar método alternativo via PDO si mysqldump falla
                return $this->downloadViaPdo($database, $filename);
            }

            // Verificar que el archivo tenga contenido
            if (!file_exists($filepath) || filesize($filepath) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo de backup está vacío. Verifica los permisos de mysqldump.',
                ], 500);
            }

            return Response::download($filepath, $filename, [
                'Content-Type'        => 'application/sql',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el backup: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Método alternativo: genera el SQL usando PDO.
     * Útil en hostings donde mysqldump no está disponible.
     */
    private function downloadViaPdo(string $database, string $filename)
    {
        $pdo    = DB::connection()->getPdo();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        $sql  = "-- EcuaPos — Backup generado el " . now()->toDateTimeString() . "\n";
        $sql .= "-- Base de datos: {$database}\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Estructura de la tabla
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")
                              ->fetch(\PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createStmt['Create Table'] . ";\n\n";

            // Datos de la tabla
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql    .= "INSERT INTO `{$table}` ({$columns}) VALUES\n";

                $valueLines = [];
                foreach ($rows as $row) {
                    $values = array_map(function ($val) use ($pdo) {
                        return $val === null ? 'NULL' : $pdo->quote((string) $val);
                    }, $row);
                    $valueLines[] = '(' . implode(', ', $values) . ')';
                }

                // Insertar en bloques de 500 filas para no generar líneas gigantes
                $chunks = array_chunk($valueLines, 500);
                foreach ($chunks as $i => $chunk) {
                    if ($i > 0) {
                        $sql .= "INSERT INTO `{$table}` ({$columns}) VALUES\n";
                    }
                    $sql .= implode(",\n", $chunk) . ";\n\n";
                }
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return Response::make($sql, 200, [
            'Content-Type'        => 'application/sql',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length'      => strlen($sql),
        ]);
    }
}
