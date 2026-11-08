<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number')->unique();
            $table->text('title');                // AES-256-GCM
            $table->string('category');
            $table->string('status')->default('open'); // open | investigating | closed
            $table->text('description')->nullable();   // AES-256-GCM
            $table->text('reporter')->nullable();      // AES-256-GCM
            $table->foreignId('owner_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('case_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer'); // owner | investigator | viewer
            $table->timestamps();
            $table->unique(['case_id', 'user_id']);
        });

        Schema::create('case_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->text('original_name');          // AES-256-GCM
            $table->string('mime_type')->default('application/octet-stream');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);           // hash plaintext, untuk verifikasi integritas setelah dekripsi
            $table->string('storage_path');
            $table->text('wrapped_key');            // DEK berkas, dibungkus AES-256-GCM
            $table->timestamps();
        });

        Schema::create('file_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('case_file_id')->constrained('case_files')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamps();
            $table->unique(['case_file_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('body'); // AES-256-GCM(ciphertext Vigenère dari browser)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('file_permissions');
        Schema::dropIfExists('case_files');
        Schema::dropIfExists('case_user');
        Schema::dropIfExists('cases');
    }
};
