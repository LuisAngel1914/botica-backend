<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::create('operational_locks', function (Blueprint $table) { $table->string('name')->primary(); $table->timestamps(); }); DB::table('operational_locks')->insert(['name' => 'cash_register', 'created_at' => now(), 'updated_at' => now()]); }
    public function down(): void { Schema::dropIfExists('operational_locks'); }
};
