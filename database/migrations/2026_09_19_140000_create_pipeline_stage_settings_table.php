<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('pipeline_stage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->decimal('probability', 3, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stage_settings');
    }
};
