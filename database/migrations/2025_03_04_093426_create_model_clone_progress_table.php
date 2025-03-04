<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('model_clone_progress', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId("model_clone_id")->nullable()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string("model_type");
            $table->unsignedBigInteger("source_id");
            $table->unsignedBigInteger("clone_id");
    
            $table->index(["model_type", "source_id"]);
            $table->index(["model_type", "clone_id"]);

            $table->unique(["model_type", "clone_id"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_clone_progress');
    }
};
