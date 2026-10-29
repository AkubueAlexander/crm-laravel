<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 12.2: provider choice per tenant + a history of sync runs. At most one queued/running run per tenant (partial unique index).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_sync_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('log');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('ledger_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('status', 12)->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('accounts_synced')->default(0);
            $table->unsignedInteger('entries_synced')->default(0);
            // Highest journal entry id this run pushed: the next run of the same provider continues after it.
            $table->unsignedBigInteger('to_entry_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
            $table->index(['tenant_id', 'provider', 'status']);
        });

        DB::statement("ALTER TABLE ledger_sync_runs ADD CONSTRAINT ledger_sync_runs_status_check CHECK (status IN ('queued','running','succeeded','failed'))");
        DB::statement("CREATE UNIQUE INDEX ledger_sync_runs_one_active ON ledger_sync_runs (tenant_id) WHERE status IN ('queued','running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_sync_runs');
        Schema::dropIfExists('ledger_sync_settings');
    }
};
