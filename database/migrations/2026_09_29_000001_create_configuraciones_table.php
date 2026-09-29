<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_comercial', 120)->default('Botica L y L');
            $table->string('razon_social', 180)->nullable();
            $table->string('ruc', 11)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('logo_url')->nullable();
            $table->string('moneda', 3)->default('PEN');
            $table->string('simbolo_moneda', 5)->default('S/');
            $table->string('impuesto_nombre', 20)->default('IGV');
            $table->decimal('impuesto_porcentaje', 5, 2)->default(18);
            $table->string('serie_comprobante', 10)->default('B001');
            $table->unsignedInteger('stock_minimo_default')->default(5);
            $table->unsignedInteger('dias_alerta_vencimiento')->default(60);
            $table->string('mensaje_ticket', 255)->default('Gracias por su preferencia. Conserve su ticket para reclamos.');
            $table->timestamps();
        });

        DB::table('configuraciones')->insert([
            'id' => 1,
            'nombre_comercial' => 'Botica L y L',
            'moneda' => 'PEN',
            'simbolo_moneda' => 'S/',
            'impuesto_nombre' => 'IGV',
            'impuesto_porcentaje' => 18,
            'serie_comprobante' => 'B001',
            'stock_minimo_default' => 5,
            'dias_alerta_vencimiento' => 60,
            'mensaje_ticket' => 'Gracias por su preferencia. Conserve su ticket para reclamos.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
