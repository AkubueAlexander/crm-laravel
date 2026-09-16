<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // No Board model/table exists in the blueprint yet — this represents
            // "the tenant's single pipeline board" for now, keeping the
            // tenant.{id}.board.{id} channel-naming pattern from 3.4/6.0/6.1
            // intact so a real multi-board feature can be added later without
            // changing the channel contract.
            $table->unsignedBigInteger('board_id')->default(1)->after('tenant_id');
            $table->index(['tenant_id', 'board_id']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('board_id');
        });
    }
};
