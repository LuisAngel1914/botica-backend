<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesDB;
use IlluminateSupportFacadesSchema;

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
