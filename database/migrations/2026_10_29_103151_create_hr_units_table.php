<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 13.1a: teams and territories share one table, split by kind. Names are unique per tenant and kind, case-insensitively.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'kind']);
        });

        DB::statement("ALTER TABLE hr_units ADD CONSTRAINT hr_units_kind_check CHECK (kind IN ('team', 'territory'))");
        DB::statement('CREATE UNIQUE INDEX hr_units_tenant_kind_name_unique ON hr_units (tenant_id, kind, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_units');
    }
};
