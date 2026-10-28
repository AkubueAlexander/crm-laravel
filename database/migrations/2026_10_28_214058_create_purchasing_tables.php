<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 11.2a: suppliers and purchase orders. A PO is a draft you can edit, then "ordered"; goods are received against it
// line by line (partial receipts allowed), and each receipt is a ledger movement written by RecordStockMovementAction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        // Case-insensitive unique name per tenant (race guard behind the friendly pre-check).
        DB::statement('CREATE UNIQUE INDEX suppliers_tenant_name_unique ON suppliers (tenant_id, lower(name))');

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('po_seq');
            $table->string('po_number', 24);
            // draft | ordered | partially_received | received | cancelled
            $table->string('status', 24)->default('draft');
            // 3.0 pattern: optimistic-concurrency counter, bumped on every change of the PO.
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->date('expected_date')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'po_seq']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'po_number']);
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'ordered', 'partially_received', 'received', 'cancelled'))");

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();

            $table->index('purchase_order_id');
        });

        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_quantity_pos CHECK (quantity > 0)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_cost_nonneg CHECK (unit_cost >= 0)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_received_range CHECK (received_quantity >= 0 AND received_quantity <= quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
