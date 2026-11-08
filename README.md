# CASELOCK — Confidential Case Management System

CASELOCK adalah aplikasi web untuk mengelola database kasus sensitif (penipuan digital, kebocoran data, pencurian identitas, investigasi internal). Bukti disimpan terenkripsi **per berkas**; pengguna harus lolos autentikasi, punya izin, dan membuktikan diri sekali lagi sebelum satu berkas didekripsi (**Selective Decryption**). Setiap pembukaan dicatat di audit log berantai (hash chain SHA-256).

## Daftar isi

1. [Fitur](#1-fitur)
2. [Tech stack](#2-tech-stack)
3. [Cara kerja kriptografi](#3-cara-kerja-kriptografi)
4. [Struktur folder](#4-struktur-folder)
5. [Instalasi](#5-instalasi)
6. [Menjalankan aplikasi](#6-menjalankan-aplikasi)
7. [Panduan penggunaan langkah demi langkah](#7-panduan-penggunaan-langkah-demi-langkah)
8. [Peran dan hak akses](#8-peran-dan-hak-akses)
9. [Pengujian](#9-pengujian)
10. [Produksi dan hardening](#10-produksi-dan-hardening)
11. [Batasan yang perlu diketahui](#11-batasan-yang-perlu-diketahui)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Fitur

| Fitur | Keterangan |
|---|---|
| Manajemen kasus | Nomor kasus otomatis (`CL-2026-0001`), kategori, status (terbuka / diselidiki / ditutup), anggota per kasus |
| Bukti terenkripsi per berkas | Tiap berkas punya kunci acak sendiri; dienkripsi XChaCha20-Poly1305 |
| **Selective Decryption** | Membuka satu berkas tidak membuka berkas lain; butuh izin per berkas + re-autentikasi |
| Login berlapis | Password (Argon2id) + passkey WebAuthn (Face ID, sidik jari, USB FIDO2 key); bisa juga login passkey-only |
| Pesan terenkripsi | Vigenère Cipher (di browser) lalu AES-256-GCM (di server) |
| Field database terenkripsi | Judul, deskripsi, pelapor kasus, nama berkas: AES-256-GCM |
| Steganografi LSB | Sisipkan / ekstrak pesan tersembunyi pada gambar PNG, opsional dilindungi passphrase |
| Audit log berantai | SHA-256 hash chain, append-only di level PostgreSQL, ada tombol verifikasi integritas |
| Hak akses | 3 peran (admin, investigator, viewer) + peran per kasus + izin per berkas |

## 2. Tech stack

- **Frontend:** React 18, TypeScript, Tailwind CSS 3, Vite
- **Backend:** Laravel 12 (PHP 8.2+), REST API
- **Database:** PostgreSQL
- **Kriptografi:** libsodium (ext-sodium), OpenSSL (AES-GCM), Argon2id bawaan PHP, WebAuthn (implementasi sendiri, tanpa library tambahan)

## 3. Cara kerja kriptografi

| Data | Algoritma | Catatan |
|---|---|---|
| Password | **Argon2id** | Hash disimpan di `users.password` |
| Login biometrik / dongle | **WebAuthn / Passkeys** (ES256, RS256) | Face ID, fingerprint, USB FIDO2 key. Challenge sekali pakai, origin dan RP ID divalidasi, sign counter dicek |
| Berkas bukti | **XChaCha20-Poly1305** | Kunci acak 256-bit per berkas (DEK); `nonce(24) ‖ ciphertext+tag` disimpan di disk privat |
| Kunci berkas (DEK) | **AES-256-GCM** | DEK dibungkus dengan kunci turunan master key (HKDF-SHA256), disimpan di `case_files.wrapped_key` |
| Field database sensitif | **AES-256-GCM** | Kunci berbeda per kolom (HKDF dengan konteks) |
| Pesan teks | **Vigenère + AES-256-GCM** | Vigenère di browser (kata kunci tidak dikirim ke server), AES-GCM di server terikat ke ID kasus |
| Gambar tersembunyi | **LSB steganography** | Bit terendah kanal R, G, B; format `CLK1 ‖ panjang ‖ data`; opsional AES-GCM + PBKDF2 (250.000 iterasi) |
| Audit log | **SHA-256 hash chain** | `hash_n = SHA256(hash_{n-1} ‖ seq ‖ user ‖ aksi ‖ objek ‖ detail ‖ waktu)` |

### Selective Decryption

```
Unggah  : plaintext ──XChaCha20-Poly1305(DEK acak)──► blob di disk
          DEK ──AES-256-GCM(kunci turunan master key)──► wrapped_key di database
          (AAD mengikat ciphertext ke ID berkas + ID kasus: tidak bisa dipindah/ditukar)

Buka    : 1. Anggota kasus?            ─ tidak → ditolak + dicatat
          2. Punya izin berkas ini?    ─ tidak → ditolak + dicatat
          3. Re-autentikasi (passkey dengan user verification, atau password) ─ gagal → ditolak + dicatat
          4. Unwrap DEK berkas ini saja → dekripsi di memori → cek SHA-256 → kirim → dicatat
          Berkas lain di kasus yang sama tetap terenkripsi.
```

### Audit log berantai

Setiap catatan menyimpan `prev_hash` dan `hash`. Mengubah, menghapus, atau menyisipkan satu baris memutus rantai pada baris itu; tombol **Verifikasi integritas rantai** (menu Audit Log, admin) menghitung ulang seluruh rantai. Selain itu, trigger PostgreSQL menolak `UPDATE`/`DELETE` pada tabel `audit_logs`.

## 4. Struktur folder

```
caselock/
├── README.md
├── scripts/
│   └── setup-backend.sh        # membuat proyek Laravel + menyalin kode CASELOCK + migrasi
├── server/                     # kode khusus CASELOCK untuk Laravel (di-overlay ke backend/)
│   ├── app/
│   │   ├── Casts/Encrypted.php               # cast field terenkripsi AES-256-GCM
│   │   ├── Console/Commands/                 # caselock:generate-key, caselock:create-user
│   │   ├── Http/Controllers/                 # Auth, Passkey, User, Case, File, Message, Audit
│   │   ├── Http/Middleware/                  # ApiTokenAuth, RequireRole, SecurityHeaders
│   │   ├── Models/
│   │   ├── Services/                         # AuditLogger, WebAuthnService, StepUpAuth
│   │   └── Support/                          # CryptoService, HashChain, CBOR, WebAuthnVerifier, CaseAccess
│   ├── bootstrap/app.php
│   ├── config/caselock.php
│   ├── database/migrations/
│   ├── routes/api.php
│   ├── tests/selftest.php                    # uji kriptografi tanpa Laravel/DB
│   └── env.caselock.example                  # variabel .env yang dibutuhkan
├── backend/                    # DIBUAT oleh setup (Laravel + kode di server/)
└── frontend/                   # React + TypeScript + Tailwind
    ├── src/lib/                # api, webauthn, vigenere, stego
    ├── src/components/ · src/pages/
    └── tests/crypto.test.ts
```

## 5. Instalasi

### Prasyarat

- **PHP 8.2+** dengan ekstensi `sodium`, `openssl`, `pdo_pgsql`, `mbstring` (cek: `php -m`)
- **Composer 2**
- **PostgreSQL 14+** yang sedang berjalan
- **Node.js 20+** dan npm

Ubuntu/Debian (contoh): `sudo apt install php php-cli php-pgsql php-mbstring php-xml php-curl composer postgresql nodejs npm`

### Langkah 1 — Siapkan database

```bash
sudo -u postgres psql -c "CREATE USER caselock WITH PASSWORD 'ganti-password-ini';"
sudo -u postgres psql -c "CREATE DATABASE caselock OWNER caselock;"
```

### Langkah 2 — Siapkan backend

**Linux / macOS / WSL (otomatis):**

```bash
cd caselock
DB_PASSWORD='ganti-password-ini' ./scripts/setup-backend.sh
```

Skrip ini: membuat proyek Laravel di `backend/`, menyalin isi `server/`, mengisi `.env` (Argon2id, PostgreSQL, WebAuthn), membuat `APP_KEY` dan **master key CASELOCK**, lalu menjalankan migrasi. Variabel opsional: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

**Windows (manual, PowerShell):**

```powershell
cd caselock
composer create-project laravel/laravel:^12.0 backend
xcopy server backend /E /Y /I
cd backend
copy .env.example .env      # lalu gabungkan isi env.caselock.example ke .env (ganti DB_PASSWORD)
php artisan key:generate
php artisan caselock:generate-key --write
php artisan migrate
```

Variabel yang wajib ada di `backend/.env` ada di `server/env.caselock.example` (termasuk `HASH_DRIVER=argon2id`, `DB_CONNECTION=pgsql`, `CACHE_STORE=file`, `SESSION_DRIVER=file`).

> ⚠️ **Cadangkan `CASELOCK_MASTER_KEY`** (di `backend/.env`) di tempat aman, terpisah dari server. Tanpa kunci ini, seluruh bukti dan field terenkripsi **tidak dapat dipulihkan**.

### Langkah 3 — Buat akun admin pertama

```bash
cd backend
php artisan caselock:create-user admin@contoh.com --name="Admin" --role=admin
# password (min. 12 karakter) akan ditanyakan secara interaktif
```

### Langkah 4 — Siapkan frontend

```bash
cd frontend
npm install
```

## 6. Menjalankan aplikasi

Buka dua terminal:

```bash
# Terminal 1 — backend (http://localhost:8000)
cd backend && php artisan serve

# Terminal 2 — frontend (http://localhost:5173)
cd frontend && npm run dev
```

Buka **http://localhost:5173** (bukan port 8000). Frontend mem-proxy `/api` ke Laravel.

> Passkey/WebAuthn hanya berjalan di `localhost` atau **HTTPS**. Pengaturan bawaan memakai `CASELOCK_RP_ID=localhost` dan `CASELOCK_ORIGINS=http://localhost:5173`. Jika Anda mengakses lewat alamat lain (IP LAN, domain), ubah dua variabel itu agar sama persis dengan alamat di browser, lalu restart backend.

## 7. Panduan penggunaan langkah demi langkah

### Langkah 1 — Login pertama
1. Buka http://localhost:5173.
2. Masukkan email dan password admin yang dibuat di Langkah 3, klik **Masuk**.

### Langkah 2 — Daftarkan passkey (sangat disarankan)
1. Menu **Keamanan Akun** → isi nama perangkat (mis. “Laptop”, “YubiKey”) → **+ Daftarkan passkey**.
2. Ikuti prompt browser: Face ID / sidik jari / Windows Hello, atau colok USB Security Key lalu sentuh.
3. Setelah terdaftar, login berikutnya meminta **password lalu passkey**. Anda juga bisa memakai tombol **Masuk dengan Passkey** di halaman login.
4. Daftarkan lebih dari satu passkey (mis. perangkat + key cadangan) agar tidak terkunci.

### Langkah 3 — Buat pengguna (admin)
1. Menu **Pengguna** → **+ Pengguna Baru**: isi nama, email, password awal (min. 12 karakter), peran.
2. Minta pengguna login lalu mendaftarkan passkey sendiri.

### Langkah 4 — Buat kasus
1. Menu **Kasus** → **+ Kasus Baru**: judul, kategori, pelapor, deskripsi.
2. Judul/deskripsi/pelapor disimpan terenkripsi. Anda otomatis menjadi **pemilik** kasus.

### Langkah 5 — Tambah anggota kasus
1. Buka kasus → tab **Anggota** → pilih pengguna dan peran (`viewer` hanya baca, `investigator` boleh menambah berkas/pesan) → **Tambah**.
2. Anggota baru **belum bisa membuka berkas apa pun** sampai diberi izin per berkas (Langkah 7).

### Langkah 6 — Unggah bukti
1. Tab **Berkas Bukti** → pilih berkas (maks. 25 MB secara bawaan) → otomatis dienkripsi dan disimpan.
2. Pengunggah otomatis mendapat izin membuka berkasnya sendiri. Nama berkas, ukuran, dan hash SHA-256 tampil; isinya tidak.

### Langkah 7 — Atur izin per berkas (pemilik kasus / admin)
1. Di baris berkas, klik **Izin** → centang anggota yang boleh mendekripsi berkas itu → **Simpan izin**.
2. Berkas tanpa izin tampil sebagai **Terkunci** bagi anggota lain.

### Langkah 7b — Buka (dekripsi) satu berkas
1. Klik **Dekripsi** pada berkas yang Anda punya izinnya.
2. Verifikasi ulang: **Verifikasi dengan Passkey** (disarankan) atau masukkan password.
3. Berkas didekripsi **hanya itu saja**; browser menampilkan pratinjau (gambar/teks) dan status **integritas SHA-256**. Klik **Unduh** bila perlu. Tutup jendela untuk membuang data dari memori.
4. Pembukaan (berhasil maupun ditolak) tercatat di audit log.

### Langkah 8 — Pesan terenkripsi
1. Tab **Pesan** → tulis pesan, isi **kata kunci Vigenère** (min. 4 huruf) → **Enkripsi & Kirim**.
2. Bagikan kata kunci ke penerima lewat jalur terpisah. Kata kunci **tidak dikirim ke server dan tidak disimpan**; jika hilang, pesan tidak bisa dibuka.
3. Penerima klik **Buka** (tercatat di audit log), lalu memasukkan kata kunci untuk **Dekripsi**.

### Langkah 9 — Steganografi
1. Menu **Steganografi** → **Sisipkan pesan**: pilih gambar sampul, tulis pesan, isi passphrase (disarankan) → **Sisipkan ke gambar** → unduh PNG.
2. Untuk membaca: **Ekstrak pesan** → pilih PNG hasil → passphrase → **Ekstrak**.
3. Jangan kompres ulang gambar (JPEG, screenshot, aplikasi chat) karena data tersembunyi akan rusak. PNG hasil bisa diunggah sebagai berkas bukti.

### Langkah 10 — Audit log
- **Per kasus:** tab **Audit Log** di dalam kasus (pemilik kasus / admin).
- **Seluruh sistem:** menu **Audit Log** (admin) → **Verifikasi integritas rantai**. Hasil hijau = rantai utuh; merah = menunjuk nomor catatan pertama yang rusak.

### Langkah 11 — Keluar
Klik **Keluar**. Token sesi disimpan di `sessionStorage`, sehingga juga hilang saat tab ditutup, dan kedaluwarsa otomatis setelah `CASELOCK_TOKEN_TTL` menit (bawaan 480).

## 8. Peran dan hak akses

| Tindakan | admin | investigator | viewer |
|---|:-:|:-:|:-:|
| Buat kasus | ✅ | ✅ | ❌ |
| Lihat metadata kasus | semua kasus | kasus yang diikuti | kasus yang diikuti |
| Tambah berkas / kirim pesan | jika anggota (owner/investigator) | jika anggota (owner/investigator) | ❌ |
| Kelola anggota, status, izin berkas | ✅ semua kasus | hanya kasus miliknya | ❌ |
| **Dekripsi berkas** | hanya dengan izin berkas | hanya dengan izin berkas | hanya dengan izin berkas |
| Kelola pengguna, verifikasi rantai audit | ✅ | ❌ | ❌ |

Admin **tidak otomatis** bisa membuka isi berkas: admin harus menambahkan dirinya sebagai anggota kasus (tab Anggota) lalu memberi izin pada berkas yang bersangkutan, dan semuanya tercatat di audit log.

## 9. Pengujian

```bash
# Kriptografi server: AES-GCM, XChaCha20 + selective decryption, hash chain, CBOR, WebAuthn (ES256/RS256)
php server/tests/selftest.php            # atau: php backend/tests/selftest.php

# Vigenère + steganografi LSB (Node >= 22.6)
cd frontend && npm run test:crypto
```

Kedua uji tidak membutuhkan database maupun browser. Uji ini memverifikasi antara lain: berkas yang dipindah ke kasus/ID lain ditolak, bit-flip terdeteksi, assertion passkey dengan challenge/origin/RP ID/tanda tangan salah ditolak, dan baris audit yang diubah/dihapus memutus rantai.

**Demo deteksi manipulasi audit log** (hanya untuk uji coba di database lokal): trigger append-only melindungi tabel, jadi nonaktifkan dulu sebagai superuser:

```sql
ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only;
UPDATE audit_logs SET action = 'file.view' WHERE seq = 3;
ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only;
```

Lalu klik **Verifikasi integritas rantai**: hasilnya merah dan menunjuk catatan #3.

## 10. Produksi dan hardening

1. **HTTPS wajib.** Set `APP_ENV=production`, `APP_DEBUG=false`.
2. **WebAuthn:** `CASELOCK_RP_ID=contoh.com` (domain saja) dan `CASELOCK_ORIGINS=https://caselock.contoh.com` (alamat frontend persis). Mengubah RP ID membuat passkey lama tidak berlaku.
3. **Deploy:** `cd frontend && npm run build`, sajikan `frontend/dist` dari domain yang sama, dan teruskan `/api/*` ke `backend/public/index.php` (Nginx/Apache + PHP-FPM). Jika frontend dan API berbeda domain, tambahkan konfigurasi CORS.
4. **Master key:** simpan di secret manager / KMS, bukan hanya di `.env`. Cadangkan terpisah dari server.
5. **Cadangan:** database PostgreSQL **dan** folder `backend/storage/app/private/caselock/evidence/`. Keduanya tidak berguna tanpa master key; master key tanpa keduanya juga tidak berguna.
6. **Database:** pakai user PostgreSQL khusus aplikasi (bukan superuser) agar trigger append-only tidak bisa dinonaktifkan oleh aplikasi.
7. **Ekspor hash ujung** audit log secara berkala (tampil saat verifikasi) ke tempat terpisah, supaya penulisan ulang seluruh rantai oleh pihak yang menguasai database tetap terdeteksi.
8. Batasi akses jaringan ke PostgreSQL dan aktifkan rate limit di reverse proxy.

## 11. Batasan yang perlu diketahui

- **Belum diaudit keamanan.** Gunakan untuk pembelajaran / prototipe; sebelum memegang data sungguhan, lakukan review keamanan independen.
- **WebAuthn ditulis sendiri** (attestation `none`, ES256 dan RS256). Sudah diuji dengan authenticator tiruan, tetapi belum dicoba dengan setiap perangkat. Untuk produksi, pertimbangkan library teruji seperti `web-auth/webauthn-lib`.
- **Vigenère adalah cipher klasik yang lemah** (angka dan tanda baca tidak berubah, rentan analisis frekuensi). Perlindungan sebenarnya datang dari AES-256-GCM dan TLS; Vigenère di sini sebagai lapisan tambahan sesuai spesifikasi.
- **Berkas dienkripsi sekali jalan di memori** (batas 25 MB bawaan, atur `CASELOCK_MAX_FILE_MB` dan `upload_max_filesize`/`post_max_size` PHP). Untuk berkas besar perlu enkripsi streaming (`secretstream`).
- **Master key berasal dari `.env`**; belum ada rotasi kunci otomatis.
- **Bukti tidak bisa dihapus** dari aplikasi (disengaja, demi integritas).
- **Token sesi di `sessionStorage`** rentan bila ada celah XSS; untuk produksi pertimbangkan cookie `HttpOnly` + proteksi CSRF.
- Hash chain bersifat *tamper-evident* (mendeteksi), bukan *tamper-proof* (mencegah) terhadap pihak yang menguasai seluruh database.

## 12. Troubleshooting

| Gejala | Penyebab / solusi |
|---|---|
| `CASELOCK_MASTER_KEY belum diatur` | Jalankan `php artisan caselock:generate-key --write` di `backend/` |
| `could not find driver` | Aktifkan ekstensi `pdo_pgsql` di PHP |
| `Origin tidak diizinkan` / `SecurityError` saat passkey | `CASELOCK_ORIGINS` dan `CASELOCK_RP_ID` harus sama dengan alamat di address bar; restart `php artisan serve` setelah mengubah `.env` |
| Tombol passkey tidak muncul / error WebAuthn | Gunakan `localhost` atau HTTPS; browser modern (Chrome, Edge, Safari, Firefox) |
| `Challenge tidak ditemukan atau sudah kedaluwarsa` | Challenge berlaku 5 menit dan sekali pakai; ulangi prosesnya. Pastikan `CACHE_STORE=file` (atau cache bersama bila banyak server) |
| `Sign counter mundur` | Kredensial kemungkinan diklon; hapus passkey lalu daftarkan ulang. (Passkey yang selalu melapor counter 0, mis. iCloud, tetap diterima) |
| Data steganografi tidak ditemukan | Gambar sudah dikompres/diubah. Ekstrak dari PNG asli hasil CASELOCK |
| `Berkas terenkripsi tidak ditemukan` | Folder `storage/app/private/caselock/evidence` hilang / tidak ikut dicadangkan |
| `[DATA RUSAK / TIDAK DAPAT DIDEKRIPSI]` | Master key berbeda dari saat data dibuat, atau data dimanipulasi |
| Upload gagal untuk berkas besar | Naikkan `upload_max_filesize` dan `post_max_size` di `php.ini`, serta `CASELOCK_MAX_FILE_MB` |
| `audit_logs bersifat append-only` | Perilaku yang diharapkan: tabel audit tidak boleh diubah/dihapus |
| Lupa password admin | `php artisan tinker` → `User::where('email','...')->update(['password'=>Hash::driver('argon2id')->make('password-baru-12+')]);` |
