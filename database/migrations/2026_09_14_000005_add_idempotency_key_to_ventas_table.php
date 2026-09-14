<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('ventas', 'idempotency_key')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->uuid('idempotency_key')->nullable()->after('estado');
                $table->unique(['usuario_id', 'idempotency_key'], 'ventas_usuario_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ventas', 'idempotency_key')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->dropUnique('ventas_usuario_idempotency_unique');
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
