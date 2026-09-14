<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesDB;
use IlluminateSupportFacadesSchema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operational_locks', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamps();
        });

        DB::table('operational_locks')->insert([
            'name' => 'cash_register',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_locks');
    }
};
