<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 10.4a: invoices. An invoice is a SNAPSHOT of an accepted quote (lines, prices, tax as quoted): later quote or
// catalog edits never change it. Real tax calculation (TaxJar/Avalara) will plug in server-side later; today the
// amounts are carried over from the quote. Payments (10.6) will add their own table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('invoice_seq');
            $table->string('invoice_number', 24);
            // draft | issued | paid | void
            $table->string('status', 16)->default('draft');
            // 3.0 pattern: optimistic-concurrency counter, bumped on every status transition.
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->unsignedInteger('quote_seq')->nullable();
            $table->string('title', 160);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_seq']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'invoice_number']);
        });

        // One live (non-void) invoice per quote; a voided one can be re-invoiced. Race guard behind the friendly pre-check.
        DB::statement("CREATE UNIQUE INDEX invoices_one_live_per_quote ON invoices (tenant_id, quote_seq) WHERE quote_seq IS NOT NULL AND status <> 'void'");

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('tax_category', 16);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
