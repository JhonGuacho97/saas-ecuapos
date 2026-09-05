<?php

namespace Tests\Feature;

use App\DotenvEditor;
use App\Exceptions\Handler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Tests\TestCase;

class EnvironmentReliabilityTest extends TestCase
{
    public function test_mail_environment_update_preserves_database_configuration(): void
    {
        $directory = sys_get_temp_dir().'/ecuapos-env-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/.env';
        file_put_contents($path, "DB_USERNAME=test_user\nDB_DATABASE=test_database\nDB_PASSWORD=example-only\nMAIL_HOST=old.example.test\n");
        config(['dotenveditor.pathToEnv' => $path, 'dotenveditor.backupPath' => $directory]);

        try {
            $editor = new DotenvEditor();
            $editor->addData(['MAIL_HOST' => 'new.example.test', 'MAIL_FROM_NAME' => 'EcuaPos SaaS']);
            $values = \Dotenv\Dotenv::parse(file_get_contents($path));
            $this->assertSame('test_user', $values['DB_USERNAME']);
            $this->assertSame('test_database', $values['DB_DATABASE']);
            $this->assertSame('example-only', $values['DB_PASSWORD']);
            $this->assertSame('new.example.test', $values['MAIL_HOST']);
            $this->assertSame('EcuaPos SaaS', $values['MAIL_FROM_NAME']);
            $this->assertSame(['.', '..', '.env'], scandir($directory));
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    public function test_database_connection_errors_are_reported_without_exposing_sql_or_credentials(): void
    {
        $request = Request::create('/api/config');
        $request->headers->set('Accept', 'application/json');
        foreach ([1045, 1049, 2002, 2003, 2006, 2013] as $errorCode) {
            $previous = new PDOException("Access denied for user 'forge'@'localhost'");
            $previous->errorInfo = ['HY000', $errorCode, $previous->getMessage()];
            $exception = new QueryException('mysql', 'select * from personal_access_tokens where id = ?', [125], $previous);
            $response = $this->app->make(Handler::class)->render($request, $exception);

            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('DATABASE_UNAVAILABLE', $response->getData(true)['error_code']);
            $this->assertStringNotContainsString('forge', $response->getContent());
            $this->assertStringNotContainsString('personal_access_tokens', $response->getContent());
        }
    }
}
