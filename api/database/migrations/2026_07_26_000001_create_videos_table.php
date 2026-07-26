<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            // UUID como chave: o identificador e gerado pela API e viaja na
            // mensagem ate o worker, que o usa para nomear os objetos no storage.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('original_filename');
            $table->string('object_key');
            $table->string('zip_object_key')->nullable();

            $table->string('status', 20)->default('PENDING');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('zip_size_bytes')->nullable();
            $table->unsignedInteger('frame_count')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->text('error_message')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Consulta dominante da aplicacao: "meus videos, mais recentes primeiro".
            $table->index(['user_id', 'created_at']);
            // Suporta o filtro por status na listagem e os paineis operacionais.
            $table->index(['user_id', 'status']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
