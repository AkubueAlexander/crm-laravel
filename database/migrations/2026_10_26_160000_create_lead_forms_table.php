<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The public identifier used in /f/{uuid}. Unguessable, never the numeric id.
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->string('source', 100)->nullable();
            $table->string('success_message', 300)->nullable();
            // [{key, label, required}] limited to the whitelist in LeadFormDsl.
            $table->jsonb('fields');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        DB::statement('create unique index lead_forms_tenant_name_unique on lead_forms (tenant_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_forms');
    }
};