<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 10.0: product catalog. Money is decimal(12,2). SKU is unique per tenant ignoring case; products are soft-deleted
// (history for quotes/invoices later), so the product index is partial and a deleted product frees its SKU.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            // standard | reduced | zero | exempt. The tax RATE is resolved server-side later (10.4), never stored here.
            $table->string('tax_category', 16)->default('standard');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'name']);
        });

        DB::statement('create unique index products_tenant_lower_sku_unique on products (tenant_id, lower(sku)) where deleted_at is null');

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name', 160);
            // null = inherits the product's unit_price
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });

        DB::statement('create unique index product_variants_tenant_lower_sku_unique on product_variants (tenant_id, lower(sku))');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
