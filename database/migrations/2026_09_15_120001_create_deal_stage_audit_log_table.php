<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            // Nullable: a system/automation-triggered transition (future) may
            // have no acting user; nullOnDelete rather than cascading, since
            // deleting a user shouldn't erase the historical audit trail.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->timestamp('transitioned_at');
            $table->timestamps();

            $table->index(['tenant_id', 'deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_audit_log');
    }
};
