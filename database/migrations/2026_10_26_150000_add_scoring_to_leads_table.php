<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 9.7: the score is COMPUTED server-side (LeadScoring) and only stored here; the SPA never writes it.
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_score')->default(0);
            // [{key, label, points}] : why the lead has this score.
            $table->jsonb('score_factors')->nullable();
            $table->timestamp('score_updated_at')->nullable();

            $table->index(['tenant_id', 'lead_score']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'lead_score']);
            $table->dropColumn(['lead_score', 'score_factors', 'score_updated_at']);
        });
    }
};