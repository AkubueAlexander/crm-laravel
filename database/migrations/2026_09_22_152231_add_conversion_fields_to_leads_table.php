<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // 8a.2: ConvertLeadAction stamps these when a Lead becomes a
            // Contact + Deal. Nullable/unset until conversion happens.
            $table->timestamp('converted_at')->nullable()->after('status');
            $table->foreignId('contact_id')->nullable()->after('converted_at')->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deal_id');
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn('converted_at');
        });
    }
};