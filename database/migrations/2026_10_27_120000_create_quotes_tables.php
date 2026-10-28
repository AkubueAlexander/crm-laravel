<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 10.1: quotes. A row is one VERSION of a quote (10.2 revise-not-overwrite): same quote_seq/quote_number, higher version.
// Totals are computed server-side only. Lines snapshot sku/name/price/tax, so later catalog edits never change a quote.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quote_seq');
            $table->string('quote_number', 24);
            $table->unsignedSmallInteger('version')->default(1);
            // draft | pending_approval | approved | rejected | sent | accepted | declined | expired (workflow arrives in 10.3)
            $table->string('status', 24)->default('draft');
            // 3.0 pattern: optimistic-concurrency counter, bumped on every status transition (10.3).
            $table->unsignedInteger('lock_version')->default(0);
            $table->string('title', 160);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'quote_seq', 'version']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'quote_number']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
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

            $table->index('quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
