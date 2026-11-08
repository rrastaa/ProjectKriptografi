<?php

namespace App\Console\Commands;

use App\Support\Crypto\CryptoService;
use Illuminate\Console\Command;

class GenerateKey extends Command
{
    protected $signature = 'caselock:generate-key {--write : Tulis ke .env bila CASELOCK_MASTER_KEY masih kosong} {--force : Timpa kunci yang sudah ada (BERBAHAYA)}';
    protected $description = 'Membuat master key CASELOCK (32 byte, base64)';

    public function handle(): int
    {
        $key = CryptoService::generateKey();

        if (!$this->option('write')) {
            $this->line($key);
            $this->warn('Simpan di CASELOCK_MASTER_KEY dan CADANGKAN di tempat aman terpisah dari server.');
            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (!is_file($envPath)) {
            $this->error('.env tidak ditemukan.');
            return self::FAILURE;
        }

        $env = file_get_contents($envPath);
        $hasKey = preg_match('/^CASELOCK_MASTER_KEY=(.+)$/m', $env, $m) && trim($m[1]) !== '';

        if ($hasKey && !$this->option('force')) {
            $this->warn('CASELOCK_MASTER_KEY sudah ada; tidak ditimpa. Menimpa kunci membuat data lama TIDAK dapat dibuka (pakai --force bila yakin).');
            return self::SUCCESS;
        }

        if (preg_match('/^CASELOCK_MASTER_KEY=.*$/m', $env)) {
            $env = preg_replace('/^CASELOCK_MASTER_KEY=.*$/m', 'CASELOCK_MASTER_KEY=' . $key, $env);
        } else {
            $env = rtrim($env) . PHP_EOL . 'CASELOCK_MASTER_KEY=' . $key . PHP_EOL;
        }
        file_put_contents($envPath, $env);

        $this->info('Master key ditulis ke .env.');
        $this->warn('CADANGKAN nilai CASELOCK_MASTER_KEY. Jika hilang, semua bukti terenkripsi tidak dapat dipulihkan.');
        return self::SUCCESS;
    }
}
