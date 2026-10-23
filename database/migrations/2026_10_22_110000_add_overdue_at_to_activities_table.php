<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // Set by activities:flag-overdue; cleared by complete / reopen / due_at moved forward.
            $table->timestampTz('overdue_at')->nullable();
        });

        // The scheduled scan runs across ALL tenants, so it cannot use the tenant-led
        // index. A small partial index covers exactly the rows it looks for.
        DB::statement(
            'CREATE INDEX activities_overdue_scan_idx ON activities (due_at) '
            . 'WHERE completed_at IS NULL AND overdue_at IS NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS activities_overdue_scan_idx');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('overdue_at');
        });
    }
};
