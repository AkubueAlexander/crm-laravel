<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11.0a: inventory. inventory_items holds the current on-hand per SKU (a cache of the ledger, only ever written by
 * RecordStockMovementAction under a row lock). inventory_movements is the append-only ledger: one signed row per
 * stock change with the balance after it. CHECK constraints are the last line of defence behind the Actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name', 160);
            $table->string('unit', 24)->default('unit');
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
        });

        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_on_hand_nonneg CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_reorder_nonneg CHECK (reorder_level >= 0)');

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('type', 16); // receipt | issue | adjustment
            $table->decimal('quantity_delta', 14, 3); // signed
            $table->decimal('balance_after', 14, 3);
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'inventory_item_id', 'id']);
        });

        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_check CHECK (type IN ('receipt', 'issue', 'adjustment'))");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_balance_nonneg CHECK (balance_after >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};
