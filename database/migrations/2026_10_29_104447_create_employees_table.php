<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 13.1b: employees. Optionally linked to a login user (one employee per user), one team and one territory (hr_units).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->date('hire_date')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('hr_units')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('hr_units')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'team_id']);
            $table->index(['tenant_id', 'territory_id']);
        });

        DB::statement('CREATE UNIQUE INDEX employees_tenant_user_unique ON employees (tenant_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX employees_tenant_email_unique ON employees (tenant_id, lower(email)) WHERE email IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
