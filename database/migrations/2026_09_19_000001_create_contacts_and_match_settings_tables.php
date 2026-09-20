<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pg_trgm is a "trusted" extension on PG13+, so the DB owner can create it.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // FK to accounts is added in 8a.0 when the accounts table exists.
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('job_title', 150)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Generated columns are added with raw SQL so the expressions are explicit and
        // guaranteed identical to what the PHP-side normalization assumes.
        DB::statement(<<<'SQL'
            ALTER TABLE contacts
                ADD COLUMN name_normalized text GENERATED ALWAYS AS (
                    lower(trim(coalesce(first_name, '') || ' ' || last_name))
                ) STORED,
                ADD COLUMN email_normalized text GENERATED ALWAYS AS (
                    nullif(lower(trim(email)), '')
                ) STORED,
                ADD COLUMN phone_key text GENERATED ALWAYS AS (
                    CASE
                        WHEN length(regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g')) >= 7
                        THEN right(regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g'), 10)
                    END
                ) STORED
        SQL);

        DB::statement('CREATE INDEX contacts_name_trgm_idx ON contacts USING GIN (name_normalized gin_trgm_ops)');
        DB::statement('CREATE INDEX contacts_tenant_email_idx ON contacts (tenant_id, email_normalized)');
        DB::statement('CREATE INDEX contacts_tenant_phone_idx ON contacts (tenant_id, phone_key)');

        // Supports the server-side sorted ContactList in 5.2.
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['tenant_id', 'last_name', 'first_name'], 'contacts_tenant_name_sort_idx');
        });

        Schema::create('contact_match_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('match_threshold')->default(75);
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE contact_match_settings ADD CONSTRAINT contact_match_threshold_range CHECK (match_threshold BETWEEN 1 AND 100)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_settings');
        Schema::dropIfExists('contacts');

    }
};
