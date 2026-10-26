<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class UpdateCampaignAction
{
    /**
     * Keys segment_id / email_template_id are optional: absent = leave the stored value, null = clear it.
     *
     * @param array{name: string, subject?: string|null, segment_id?: int|null, email_template_id?: int|null} $data
     */
    public function execute(User $actor, int $campaignId, array $data): Campaign
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        try {
            return DB::transaction(function () use ($campaignId, $data, $tenantId): Campaign {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $campaign = Campaign::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);

                if (! $campaign->isDraft()) {
                    throw ValidationException::withMessages(['status' => ['Only draft campaigns can be edited.']]);
                }

                $changes = [
                    'name' => $data['name'],
                    'subject' => $data['subject'] ?? null,
                ];

                $segmentId = $campaign->segment_id;
                $templateId = $campaign->email_template_id;

                if (array_key_exists('segment_id', $data)) {
                    $segmentId = $data['segment_id'] === null ? null : (int) $data['segment_id'];
                    $changes['segment_id'] = $segmentId;
                }

                if (array_key_exists('email_template_id', $data)) {
                    $templateId = $data['email_template_id'] === null ? null : (int) $data['email_template_id'];
                    $changes['email_template_id'] = $templateId;
                }

                // Only the refs being changed are re-checked; an unchanged ref is not the caller's input.
                CampaignAudienceRefs::assertOwned(
                    $tenantId,
                    array_key_exists('segment_id', $data) ? $segmentId : null,
                    array_key_exists('email_template_id', $data) ? $templateId : null,
                );

                $campaign->forceFill($changes)->save();

                return $campaign;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A campaign with this name already exists.']]);
        }
    }
}