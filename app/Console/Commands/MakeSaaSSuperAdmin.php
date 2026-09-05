<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeSaaSSuperAdmin extends Command
{
    protected $signature = 'saas:make-super-admin {email} {--revoke}';
    protected $description = 'Concede o revoca acceso al panel global SaaS';

    public function handle(): int
    {
        $user = User::whereRaw('lower(email) = ?', [strtolower($this->argument('email'))])->first();
        if (! $user) {
            $this->components->error('No existe un usuario con ese correo.');
            return self::FAILURE;
        }
        $user->forceFill(['is_super_admin' => ! $this->option('revoke')])->save();
        $this->components->info($this->option('revoke') ? 'Acceso revocado.' : 'Superadministrador habilitado.');
        return self::SUCCESS;
    }
}
