<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('trigger_type', 40);
            $table->jsonb('trigger_config')->default(DB::raw("'{}'::jsonb"));
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // Friendly message lives in the Action; this index is the real guard.
        DB::statement('create unique index automations_tenant_lower_name_unique on automations (tenant_id, lower(name))');
        DB::statement("alter table automations add constraint automations_status_check check (status in ('draft','active','paused'))");

        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 30);
            $table->jsonb('config')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->unique(['automation_id', 'position']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automations');
    }
};