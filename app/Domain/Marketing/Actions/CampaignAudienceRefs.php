<?php

namespace App\Domain\Marketing\Actions;

use App\Models\EmailTemplate;
use App\Models\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 9.4e: server-side re-validation (2.4) that a campaign's segment / template belong to the actor's tenant.
 * The Form Request checks the same thing for fast feedback; this is the check that is load-bearing.
 */
final class CampaignAudienceRefs
{
    public static function assertOwned(int $tenantId, ?int $segmentId, ?int $templateId): void
    {
        $errors = [];

        if ($segmentId !== null && ! self::exists(Segment::class, $tenantId, $segmentId)) {
            $errors['segment_id'] = ['The selected segment does not exist.'];
        }

        if ($templateId !== null && ! self::exists(EmailTemplate::class, $tenantId, $templateId)) {
            $errors['email_template_id'] = ['The selected template does not exist.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param class-string $model */
    private static function exists(string $model, int $tenantId, int $id): bool
    {
        $table = (new $model())->getTable();

        $query = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->exists();
    }
}