<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10.3a: quote approval workflow. tenants.quote_approval_threshold: a quote whose total is at or below it is
 * auto-approved on submit (NULL = every quote needs a human approver). The quotes columns record who submitted
 * and who decided, plus the decision note (required on rejection) and the sent timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('quote_approval_threshold', 14, 2)->nullable();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['submitted_at', 'decided_at', 'decision_note', 'sent_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('quote_approval_threshold');
        });
    }
};
