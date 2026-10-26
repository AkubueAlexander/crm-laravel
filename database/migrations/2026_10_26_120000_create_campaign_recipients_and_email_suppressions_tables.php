<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 9.4b: the recipient list is SNAPSHOTTED when a send starts, so editing the segment or a contact
        // mid-send changes nothing, and the send jobs can be re-run or resumed without double-sending.
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            // The contact may be removed later; the snapshot columns below are what gets sent to.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('email');
            $table->string('email_normalized');
            $table->string('name')->nullable();
            $table->string('job_title')->nullable();
            // pending | sent | failed | skipped
            $table->string('status', 16)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            // One address is never sent the same campaign twice, even if two contacts share it.
            $table->unique(['campaign_id', 'email_normalized']);
            $table->index(['campaign_id', 'status']);
            $table->index('tenant_id');
        });

        // Addresses a tenant must never mail again (unsubscribed, bounced, complained, manual).
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email_normalized');
            // unsubscribed | bounced | complaint | manual
            $table->string('reason', 16);
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'email_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('campaign_recipients');
    }
};