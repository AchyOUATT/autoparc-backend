<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumption_source_archives', function (Blueprint $table) {
            $table->char('sha256', 64)->primary();
            $table->string('encoding', 20);
            // Base64 avoids PostgreSQL/MySQL/SQLite binary representation differences.
            $table->longText('body');
            $table->unsignedBigInteger('original_size');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('consumption_source_archives')->exists()) {
            throw new RuntimeException('Des sources archivees existent : conserver cette table et revenir a la version applicative precedente.');
        }

        Schema::dropIfExists('consumption_source_archives');
    }
};
