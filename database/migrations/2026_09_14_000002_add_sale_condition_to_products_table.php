<?php

use Illuminate\DatabaseMigrationsMigration;
use Illuminate\DatabaseSchemaBlueprint;
use Illuminate\SupportFacadesDB;
use Illuminate\SupportFacadesSchema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('condicion_venta', 30)->default('libre');
        });

        DB::table('productos')->where('requiere_receta', true)->update([
            'condicion_venta' => 'con_receta',
        ]);
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('condicion_venta');
        });
    }
};
