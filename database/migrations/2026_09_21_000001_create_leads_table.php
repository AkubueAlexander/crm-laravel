<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company')->nullable();
            $table->string('source', 100)->nullable();
            $table->string('status')->default('new');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('converted_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('converted_deal_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};