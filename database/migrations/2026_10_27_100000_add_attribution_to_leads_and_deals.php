<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 9.9: where a lead came from. Written by the system (public form submission), copied to the deal on conversion.
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('lead_form_id')->nullable()->constrained('lead_forms')->nullOnDelete();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();

            $table->index('lead_form_id');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->string('source', 100)->nullable();
            $table->foreignId('lead_form_id')->nullable()->constrained('lead_forms')->nullOnDelete();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();

            $table->index('lead_form_id');
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropConstrainedForeignId('lead_form_id');
            $table->dropColumn(['source', 'utm_source', 'utm_medium', 'utm_campaign']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_form_id');
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
