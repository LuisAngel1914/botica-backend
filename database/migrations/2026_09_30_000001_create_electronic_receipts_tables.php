<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones', function (Blueprint $table) {
            $table->string('regimen_tributario', 20)->default('NRUS')->after('simbolo_moneda');
            $table->string('modo_emision_comprobantes', 20)->default('demo')->after('regimen_tributario');
            $table->string('tipo_comprobante_predeterminado', 20)->default('boleta')->after('modo_emision_comprobantes');
        });

        Schema::create('comprobantes_electronicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->unique()->constrained('ventas')->restrictOnDelete();
            $table->string('tipo', 20)->default('boleta');
            $table->string('modo', 20)->default('demo');
            $table->string('serie', 10);
            $table->unsignedBigInteger('correlativo');
            $table->string('numero', 32)->unique();
            $table->string('estado', 30)->default('simulado');
            $table->string('moneda', 3)->default('PEN');
            $table->decimal('total', 12, 2);
            $table->json('payload');
            $table->string('hash', 64);
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('aceptado_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['serie', 'correlativo']);
            $table->index(['modo', 'estado']);
        });

        Schema::create('comprobante_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes_electronicos')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evento', 60);
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30);
            $table->json('detalles')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['comprobante_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_eventos');
        Schema::dropIfExists('comprobantes_electronicos');

        Schema::table('configuraciones', function (Blueprint $table) {
            $table->dropColumn([
                'regimen_tributario',
                'modo_emision_comprobantes',
                'tipo_comprobante_predeterminado',
            ]);
        });
    }
};
