<?php

use Illuminate\\DatabaseMigrationsMigration;
use Illuminate\\DatabaseSchemaBlueprint;
use Illuminate\\SupportFacadesSchema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recetas_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->unique()->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('verificada_por')->constrained('users');
            $table->string('paciente_nombre');
            $table->string('paciente_documento', 30)->nullable();
            $table->string('prescriptor_nombre');
            $table->string('prescriptor_colegiatura', 50);
            $table->date('fecha_emision');
            $table->string('tipo', 20);
            $table->string('referencia', 100)->nullable();
            $table->timestamp('verificada_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas_venta');
    }
};
