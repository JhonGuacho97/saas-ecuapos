<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class InstallSaaS extends Command
{
    protected $signature = 'saas:install
        {--name= : Nombre completo del superadministrador}
        {--email= : Correo del superadministrador}
        {--password= : Contraseña (prefiera ingresarla de forma interactiva)}
        {--force : Permite crear el superadministrador en una base que ya contiene tenants}';

    protected $description = 'Inicializa una instalación SaaS limpia creando únicamente el superadministrador global';

    public function handle(): int
    {
        if (! $this->schemaIsReady()) {
            $this->components->error('La base aún no está migrada. Ejecute primero: php artisan migrate --force');

            return self::FAILURE;
        }

        $existingSuperAdmin = User::query()->where('is_super_admin', true)->first();
        if ($existingSuperAdmin) {
            $this->components->info("El SaaS ya tiene un superadministrador: {$existingSuperAdmin->email}");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && (User::query()->exists() || Store::query()->exists() || Organization::query()->exists())) {
            $this->components->error(
                'La base contiene usuarios, tiendas u organizaciones. Este instalador solo puede usarse en una instalación SaaS limpia.'
            );
            $this->line('Para promover un usuario existente use: php artisan saas:make-super-admin correo@dominio.com');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Nombre completo del superadministrador')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo del superadministrador'))));
        $password = (string) ($this->option('password') ?: $this->secret('Contraseña (mínimo 10 caracteres)'));
        $passwordConfirmation = $this->option('password')
            ? $password
            : (string) $this->secret('Confirme la contraseña');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        [$firstName, $lastName] = $this->splitName($name);

        $user = DB::transaction(function () use ($firstName, $lastName, $email, $password): User {
            $user = new User();
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => null,
                'password' => Hash::make($password),
                'language' => 'sp',
                'status' => true,
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]);
            $user->save();

            return $user;
        });

        $this->newLine();
        $this->components->info("Superadministrador creado correctamente: {$user->email}");
        $this->line('No se crearon organizaciones, tiendas, bodegas ni usuarios tenant.');

        return self::SUCCESS;
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasColumn('users', 'is_super_admin')
            && Schema::hasTable('organizations')
            && Schema::hasTable('stores');
    }

    /** @return array{0: string, 1: string|null} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
