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
            // account_id / contact_id deliberately omitted for now — those
            // tables don't exist until 8a.0 / 5.0 land later in Phase 2/3.
            // Added via a follow-up migration once they're available, rather
            // than pointing a foreign key at a table that doesn't exist yet.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->string('state')->default('lead');
            // 3.1/3.2: optimistic-concurrency guard — POST /deals/{deal}/transition
            // accepts { to_stage, lock_version } and returns 409 with the
            // current server-side state on a mismatch (next chunk).
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
