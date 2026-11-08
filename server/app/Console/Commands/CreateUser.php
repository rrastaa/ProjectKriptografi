<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'caselock:create-user {email} {--name=} {--role=admin : admin|investigator|viewer} {--password= : kosongkan agar diminta secara interaktif}';
    protected $description = 'Membuat pengguna CASELOCK (gunakan untuk admin pertama)';

    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower($this->argument('email'));
        $role = $this->option('role');

        if (!in_array($role, User::ROLES, true)) {
            $this->error('Role harus salah satu dari: ' . implode(', ', User::ROLES));
            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error("Pengguna {$email} sudah ada.");
            return self::FAILURE;
        }

        $password = $this->option('password');
        if (!$password) {
            $password = $this->secret('Password (minimal 12 karakter)');
            if ($password !== $this->secret('Ulangi password')) {
                $this->error('Password tidak sama.');
                return self::FAILURE;
            }
        }
        if (strlen((string) $password) < 12) {
            $this->error('Password minimal 12 karakter.');
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::driver('argon2id')->make($password),
            'role' => $role,
        ]);
        $audit->record(null, 'user.create', 'user', $user->id, null, ['role' => $role, 'via' => 'artisan']);

        $this->info("Pengguna {$email} ({$role}) dibuat.");
        return self::SUCCESS;
    }
}
