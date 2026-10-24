<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 32);
            // sha256 of the emailed token. The raw token is never stored.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'accepted_at']);
        });

        // At most one pending invitation per email per tenant, even under concurrent requests.
        DB::statement(
            'CREATE UNIQUE INDEX user_invitations_pending_email_unique '
            .'ON user_invitations (tenant_id, lower(email)) WHERE accepted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
