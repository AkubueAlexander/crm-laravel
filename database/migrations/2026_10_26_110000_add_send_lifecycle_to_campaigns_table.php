<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // nullOnDelete: a draft that loses its segment/template simply has no audience again.
            // Scheduled/sending/sent/failed campaigns are protected by the delete Actions (9.4a),
            // so history never silently loses its audience.
            $table->foreignId('segment_id')->nullable()->constrained('segments')->nullOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();

            // 3.0 pattern: optimistic-concurrency counter, bumped on every transition.
            $table->unsignedInteger('lock_version')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('failed_count')->default(0);

            // The scheduler (9.4c) scans (status = scheduled, scheduled_at <= now) every minute.
            $table->index(['status', 'scheduled_at']);
            // Delete guards on segments/templates look campaigns up by these.
            $table->index('segment_id');
            $table->index('email_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropConstrainedForeignId('segment_id');
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropColumn(['lock_version', 'started_at', 'completed_at', 'failed_count']);
        });
    }
};