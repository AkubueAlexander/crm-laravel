<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('subject')->nullable();
            // draft | scheduled | sending | sent | cancelled. Only 'draft' exists until 9.4 adds the send lifecycle.
            $table->string('status', 16)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            // Nullable + nullOnDelete: deleting a user must not delete their campaigns.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Filled by the send job (9.4) and the engagement webhooks (9.5); zero until then.
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        // One campaign name per tenant, ignoring case. The form request checks it for a friendly message;
        // this index is what actually holds under a race.
        DB::statement('create unique index campaigns_tenant_lower_name_unique on campaigns (tenant_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
