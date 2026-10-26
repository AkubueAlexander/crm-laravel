<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Templates\TemplateVariables;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateEmailTemplateAction
{
    /** @param array{name?: mixed, subject?: mixed, body?: mixed} $data */
    public function __invoke(User $actor, array $data): EmailTemplate
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        [$name, $subject, $body] = self::clean($data);

        try {
            return DB::transaction(function () use ($actor, $name, $subject, $body): EmailTemplate {
                $template = new EmailTemplate();
                // Tenant comes from the actor, never from the request (1.3).
                $template->forceFill([
                    'tenant_id' => $actor->tenant_id,
                    'name' => $name,
                    'subject' => $subject,
                    'body' => $body,
                    'created_by' => $actor->id,
                ])->save();

                return $template;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A template with this name already exists.']]);
        }
    }

    /**
     * Server-side re-validation (2.4): the FormRequest is not the only caller of this Action.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: string}
     */
    public static function clean(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $subject = (string) ($data['subject'] ?? '');
        $body = (string) ($data['body'] ?? '');

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Name is required.']]);
        }
        if (mb_strlen($name) > EmailTemplate::MAX_NAME) {
            throw ValidationException::withMessages(['name' => ['Name must be at most '.EmailTemplate::MAX_NAME.' characters.']]);
        }
        TemplateVariables::assertValid($subject, $body);

        return [$name, trim($subject), $body];
    }
}