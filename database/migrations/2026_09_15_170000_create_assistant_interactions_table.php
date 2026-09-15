<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('intent', 40)->nullable();
            $table->string('source', 16);
            $table->string('response_code', 64);
            $table->boolean('helpful')->nullable();
            $table->timestamp('feedback_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['response_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_interactions');
    }
};
