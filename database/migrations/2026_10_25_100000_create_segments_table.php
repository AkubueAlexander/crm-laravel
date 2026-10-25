<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            // all = every condition must match (AND), any = at least one (OR).
            $table->string('match_mode', 3)->default('all');
            // The filter DSL (9.2): a list of {field, operator, value}. Validated by SegmentDsl, never run as raw SQL.
            $table->jsonb('conditions');
            // Nullable + nullOnDelete: deleting a user must not delete their segments.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        // One segment name per tenant, ignoring case; the form request gives the friendly message, this holds under a race.
        DB::statement('create unique index segments_tenant_lower_name_unique on segments (tenant_id, lower(name))');
        DB::statement("alter table segments add constraint segments_match_mode_check check (match_mode in ('all', 'any'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
