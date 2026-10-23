<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's standard database-notification shape, with jsonb data so the
        // tenant_id stored inside it (1.4 insurance) is indexable and filterable.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        // Inbox listing: one user, one tenant, newest first.
        DB::statement("CREATE INDEX notifications_inbox_idx ON notifications (notifiable_type, notifiable_id, (data->>'tenant_id'), created_at DESC)");

        // Unread badge count.
        DB::statement("CREATE INDEX notifications_unread_idx ON notifications (notifiable_type, notifiable_id, (data->>'tenant_id')) WHERE read_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
