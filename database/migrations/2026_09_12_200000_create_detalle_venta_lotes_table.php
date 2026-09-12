<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('detalle_venta_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detalle_venta_id')->constrained('detalle_ventas')->cascadeOnDelete();
            $table->foreignId('lote_id')->constrained('lotes');
            $table->unsignedInteger('cantidad');
            $table->timestamps();
            $table->unique(['detalle_venta_id', 'lote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_venta_lotes');
    }
};
