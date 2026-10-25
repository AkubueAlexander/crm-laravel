<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('subject', 200);
            // Markdown source. Raw HTML in it is stripped at render time (TemplateRenderer).
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'updated_at']);
        });

        // Template names are unique per tenant, case-insensitively (same rule as segments).
        DB::statement('create unique index email_templates_tenant_lower_name_unique on email_templates (tenant_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};