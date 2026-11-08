<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->text('details'); // JSON string persis seperti yang di-hash
            $table->char('prev_hash', 64);
            $table->char('hash', 64)->unique();
            $table->string('logged_at', 32); // ISO-8601 UTC mikrodetik, disimpan sebagai string agar hash stabil
        });

        // Append-only di level database: UPDATE / DELETE ditolak oleh PostgreSQL.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION caselock_audit_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs bersifat append-only';
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION caselock_audit_immutable()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS caselock_audit_immutable()');
        }
        Schema::dropIfExists('audit_logs');
    }
};
