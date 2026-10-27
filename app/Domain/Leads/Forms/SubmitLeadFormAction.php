<?php

namespace App\Domain\Leads\Forms;

use App\Domain\Leads\Actions\CaptureLeadAction;
use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 9.8a-2: one public submission. Order matters: unknown/inactive form (404) -> honeypot (silent success) -> CAPTCHA
 * -> validation against the form's own field list -> create the lead. The tenant comes from the form row, never from
 * the request; nothing in the body can choose a tenant, status, owner or score.
 */
final class SubmitLeadFormAction
{
    public const DEFAULT_MESSAGE = 'Thanks! We have received your details.';

    /** @param array<string, mixed> $input */
    public function execute(string $uuid, array $input, ?string $ip): string
    {
        // Public lookup: no TenantContext exists yet, so this is a plain query on the unguessable uuid.
        $form = DB::table('lead_forms')->where('uuid', $uuid)->where('is_active', true)->first();

        if ($form === null) {
            abort(404); // unknown and inactive forms look identical
        }

        $message = $form->success_message !== null && $form->success_message !== '' ? (string) $form->success_message : self::DEFAULT_MESSAGE;

        // Honeypot: humans never see this field. A bot that fills it gets the same success, and nothing is stored.
        $trap = $input['website'] ?? null;

        if ($trap !== null && $trap !== '') {
            return $message;
        }

        $token = $input['captcha_token'] ?? null;

        if (! CaptchaVerifier::passes(is_string($token) ? $token : null, $ip)) {
            throw ValidationException::withMessages(['captcha' => ['Please complete the verification and try again.']]);
        }

        $fields = json_decode((string) $form->fields, true);
        $fields = is_array($fields) ? $fields : [];

        $clean = Validator::make($input, LeadFormDsl::submissionRules($fields))->validate();

        $tenant = Tenant::query()->find($form->tenant_id);

        if ($tenant === null) {
            abort(404);
        }

        // HTTP request scope only (the context dies with the request); queue workers clear theirs in a finally.
        TenantContext::set($tenant);

        app(CaptureLeadAction::class)->execute((int) $form->tenant_id, $clean, $form->source);

        return $message;
    }
}