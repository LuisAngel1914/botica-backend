<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('ruc', 20)->nullable()->unique();
            $table->string('contacto')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('direccion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero_documento')->nullable();
            $table->decimal('total', 12, 2);
            $table->timestamp('fecha_recepcion');
            $table->timestamps();
            $table->index(['proveedor_id', 'fecha_recepcion']);
        });

        Schema::create('detalle_compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->string('numero_lote');
            $table->integer('cantidad');
            $table->decimal('costo_unitario', 10, 2);
            $table->decimal('subtotal', 12, 2);
            $table->date('fecha_vencimiento');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_compras');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('proveedores');
    }
};