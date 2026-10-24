<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 8c.3: append-only record of administrative events (user management first). Never updated.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The actor. nullOnDelete: removing a user must not erase the history of what they did.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('users');
            $table->string('event', 64);
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Denormalised on purpose: the label must stay readable after the subject is renamed or removed.
            $table->string('subject_label')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'user_id']);
        });

        // The viewer sorts and filters the deal log by time within a tenant.
        Schema::table('deal_stage_audit_log', function (Blueprint $table) {
            $table->index(['tenant_id', 'transitioned_at'], 'dsal_tenant_transitioned_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deal_stage_audit_log', function (Blueprint $table) {
            $table->dropIndex('dsal_tenant_transitioned_idx');
        });

        Schema::dropIfExists('audit_events');
    }
};
