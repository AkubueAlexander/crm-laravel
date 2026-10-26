<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 9.6c-1: one row per (automation, person). The address is SNAPSHOTTED (like campaign_recipients) so a
        // later contact edit or delete never changes where a running sequence sends to.
        Schema::create('automation_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('email');
            $table->string('email_normalized');
            $table->string('name')->nullable();
            $table->string('job_title')->nullable();
            // active | completed | cancelled | failed
            $table->string('status', 16)->default('active');
            // Position of the LAST completed step; null until the first step ran.
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            // A person enrolls once per automation, however they got there.
            $table->unique(['automation_id', 'email_normalized']);
            $table->unique(['automation_id', 'contact_id']);
            $table->unique(['automation_id', 'lead_id']);
            $table->index(['status', 'next_run_at']);
            $table->index('tenant_id');
        });

        DB::statement("ALTER TABLE automation_enrollments ADD CONSTRAINT automation_enrollments_status_check CHECK (status IN ('active','completed','cancelled','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_enrollments');
    }
};