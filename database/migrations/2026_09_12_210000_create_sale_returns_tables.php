<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('devoluciones_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total', 12, 2);
            $table->text('motivo');
            $table->timestamps();
            $table->index(['venta_id', 'created_at']);
        });

        Schema::create('detalle_devoluciones_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucion_venta_id')->constrained('devoluciones_venta')->cascadeOnDelete();
            $table->foreignId('detalle_venta_id')->constrained('detalle_ventas');
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
            $table->unique(['devolucion_venta_id', 'detalle_venta_id']);
        });

        Schema::create('detalle_devolucion_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detalle_devolucion_venta_id')->constrained('detalle_devoluciones_venta')->cascadeOnDelete();
            $table->foreignId('detalle_venta_lote_id')->constrained('detalle_venta_lotes');
            $table->foreignId('lote_id')->constrained('lotes');
            $table->unsignedInteger('cantidad');
            $table->timestamps();
            $table->unique(['detalle_devolucion_venta_id', 'detalle_venta_lote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_devolucion_lotes');
        Schema::dropIfExists('detalle_devoluciones_venta');
        Schema::dropIfExists('devoluciones_venta');
    }
};
