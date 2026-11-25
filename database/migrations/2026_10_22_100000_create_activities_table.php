<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('activityable_type', 32);
            $table->unsignedBigInteger('activityable_id');

            $table->string('type', 16);
            $table->string('subject');
            $table->text('body')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['tenant_id', 'activityable_type', 'activityable_id', 'created_at'],
                'activities_tenant_target_idx'
            );

            $table->index(['tenant_id', 'completed_at', 'due_at'], 'activities_tenant_due_idx');
        });

        DB::statement(
            "ALTER TABLE activities ADD CONSTRAINT activities_type_check "
            . "CHECK (type IN ('call', 'email', 'meeting', 'task', 'note'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
