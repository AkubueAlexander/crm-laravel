<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->string('state')->default('lead');

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
