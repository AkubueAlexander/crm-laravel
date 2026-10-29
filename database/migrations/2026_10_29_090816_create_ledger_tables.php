<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12.0a: operational general ledger. This is the CRM-adjacent slice, NOT the books of record (QuickBooks/Xero are).
 * ledger_accounts is the chart of accounts. journal_entries / journal_lines are APPEND-ONLY double-entry postings:
 * debits = credits is enforced by PostJournalEntryAction, and a mistake is corrected by a reversing entry, never an
 * edit. CHECK constraints are the last line of defence behind the Actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('name', 160);
            // asset | liability | equity | revenue | expense
            $table->string('type', 16);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type']);
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('entry_seq');
            $table->string('entry_number', 24);
            $table->date('entry_date');
            $table->string('memo', 255)->nullable();
            // Where the entry came from (e.g. an invoice or payment, once auto-posting exists). Null = manual.
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            // Set only on a reversing entry; unique so an entry can be reversed at most once (race guard).
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'entry_seq']);
            $table->index(['tenant_id', 'entry_date']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('journal_entry_id');
            $table->index(['tenant_id', 'ledger_account_id']);
        });

        // A line is exactly one of: a positive debit or a positive credit.
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit > 0 OR credit > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
