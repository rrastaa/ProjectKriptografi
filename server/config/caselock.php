<?php

return [
    // Master key 32 byte (base64). Buat dengan: php artisan caselock:generate-key --write
    // PERINGATAN: jika kunci ini hilang, seluruh bukti terenkripsi tidak dapat dipulihkan.
    'master_key' => env('CASELOCK_MASTER_KEY'),

    // WebAuthn / Passkeys
    'rp_id' => env('CASELOCK_RP_ID', 'localhost'),
    'rp_name' => env('CASELOCK_RP_NAME', 'CASELOCK'),
    'origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CASELOCK_ORIGINS', 'http://localhost:5173'))))),

    // Sesi API
    'token_ttl_minutes' => (int) env('CASELOCK_TOKEN_TTL', 480),

    // Berkas bukti
    'max_file_mb' => (int) env('CASELOCK_MAX_FILE_MB', 25),
    'evidence_disk' => 'local', // disk privat (storage/app/private), tidak pernah dipublikasikan
    'evidence_dir' => 'caselock/evidence',

    'categories' => [
        'penipuan_digital' => 'Penipuan Digital',
        'kebocoran_data' => 'Kebocoran Data',
        'pencurian_identitas' => 'Pencurian Identitas',
        'investigasi_internal' => 'Investigasi Internal',
        'lainnya' => 'Lainnya',
    ],
];
