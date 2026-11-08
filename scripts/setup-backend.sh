#!/usr/bin/env bash
# Menyiapkan backend CASELOCK: membuat proyek Laravel, menyalin kode CASELOCK, mengatur .env, migrasi database.
# Prasyarat: PHP >= 8.2 (ext: sodium, openssl, pdo_pgsql, mbstring), Composer, PostgreSQL yang sudah berjalan.
#
# Opsional (variabel lingkungan):  DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"

command -v php >/dev/null      || { echo "PHP tidak ditemukan"; exit 1; }
command -v composer >/dev/null || { echo "Composer tidak ditemukan"; exit 1; }
for ext in sodium openssl pdo_pgsql mbstring; do
  php -r "exit(extension_loaded('$ext') ? 0 : 1);" || {
    echo "Ekstensi PHP '$ext' belum aktif"
    exit 1
  }
done

if [ ! -d "$BACKEND" ]; then
  echo ">> Membuat proyek Laravel di $BACKEND ..."
  composer create-project laravel/laravel:^12.0 "$BACKEND" --no-interaction
else
  echo ">> Folder backend sudah ada, memakai yang ada."
fi

echo ">> Menyalin kode CASELOCK (server/) ke backend/ ..."
cp -R "$ROOT/server/." "$BACKEND/"
rm -f "$BACKEND/env.caselock.example"

cd "$BACKEND"
[ -f .env ] || cp .env.example .env

set_env() { # set_env KEY VALUE
  local key="$1" value="$2"
  if grep -q "^${key}=" .env; then
    # gunakan | sebagai pemisah sed; escape karakter khusus
    local esc; esc=$(printf '%s' "$value" | sed -e 's/[&|\\]/\\&/g')
    sed -i.bak "s|^${key}=.*|${key}=${esc}|" .env && rm -f .env.bak
  else
    printf '%s=%s\n' "$key" "$value" >> .env
  fi
}

echo ">> Mengatur .env ..."
while IFS= read -r line; do
  [[ -z "$line" || "$line" == \#* ]] && continue
  key="${line%%=*}"; value="${line#*=}"
  [ "$key" = "CASELOCK_MASTER_KEY" ] && continue
  case "$key" in
    DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD) value="${!key:-$value}" ;;
  esac
  set_env "$key" "$value"
done < "$ROOT/server/env.caselock.example"

php artisan key:generate --force
php artisan caselock:generate-key --write

echo ">> Menjalankan migrasi (PostgreSQL harus sudah berjalan dan database tersedia) ..."
php artisan migrate --force

echo
echo "Backend siap. Langkah berikutnya:"
echo "  1) cd backend && php artisan caselock:create-user admin@contoh.com --name=\"Admin\" --role=admin"
echo "  2) php artisan serve            # http://localhost:8000"
echo "  3) (terminal lain) cd frontend && npm install && npm run dev   # http://localhost:5173"
echo
echo "PENTING: cadangkan nilai CASELOCK_MASTER_KEY di backend/.env. Jika hilang, semua bukti tidak bisa dibuka."
