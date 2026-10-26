<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateEmailTemplateAction
{
    /**
     * Last-write-wins (no lock_version), like segments. A template has no lifecycle until 9.4
     * attaches it to campaigns.
     *
     * @param  array{name?: mixed, subject?: mixed, body?: mixed}  $data
     */
    public function __invoke(User $actor, int $id, array $data): EmailTemplate
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        [$name, $subject, $body] = CreateEmailTemplateAction::clean($data);

        try {
            return DB::transaction(function () use ($actor, $id, $name, $subject, $body): EmailTemplate {
                $template = EmailTemplate::query()
                    ->where('tenant_id', $actor->tenant_id)
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $template->forceFill(['name' => $name, 'subject' => $subject, 'body' => $body])->save();

                return $template;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A template with this name already exists.']]);
        }
    }
}