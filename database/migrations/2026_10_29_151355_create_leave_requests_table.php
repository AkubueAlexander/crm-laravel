<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'start_date', 'end_date'], 'leave_requests_employee_range_idx');
        });

        DB::statement("alter table leave_requests add constraint leave_requests_type_check check (type in ('annual', 'sick', 'unpaid', 'other'))");
        DB::statement("alter table leave_requests add constraint leave_requests_status_check check (status in ('pending', 'approved', 'rejected', 'cancelled'))");
        DB::statement('alter table leave_requests add constraint leave_requests_dates_check check (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
