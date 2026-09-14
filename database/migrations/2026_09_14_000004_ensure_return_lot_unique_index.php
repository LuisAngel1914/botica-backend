<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('detalle_devolucion_lotes')) {
            return;
        }

        $hasUniqueIndex = collect(Schema::getIndexes('detalle_devolucion_lotes'))
            ->contains(fn (array $index) => $index['name'] === 'dv_lote_detalle_unique');

        if (! $hasUniqueIndex) {
            Schema::table('detalle_devolucion_lotes', function (Blueprint $table) {
                $table->unique(
                    ['detalle_devolucion_venta_id', 'detalle_venta_lote_id'],
                    'dv_lote_detalle_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('detalle_devolucion_lotes')) {
            Schema::table('detalle_devolucion_lotes', function (Blueprint $table) {
                $table->dropUnique('dv_lote_detalle_unique');
            });
        }
    }
};
