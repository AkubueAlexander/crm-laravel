<?php

namespace App\Http\Controllers\PublicForms;

use App\Domain\Leads\Forms\CaptchaVerifier;
use App\Domain\Leads\Forms\SubmitLeadFormAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 9.8a-2: the public (unauthenticated) side of lead forms. Only the form's name, its field list and the CAPTCHA site
 * key are exposed: no ids, no tenant, nothing about leads.
 */
class LeadFormController extends Controller
{
    public function show(string $uuid)
    {
        $form = DB::table('lead_forms')->where('uuid', $uuid)->where('is_active', true)->first();

        if ($form === null) {
            abort(404);
        }

        $fields = json_decode((string) $form->fields, true);
        $fields = is_array($fields) ? $fields : [];

        return response()->json([
            'name' => $form->name,
            'fields' => array_values(array_map(
                static fn ($f): array => [
                    'key' => (string) ($f['key'] ?? ''),
                    'label' => (string) ($f['label'] ?? ''),
                    'required' => (bool) ($f['required'] ?? false),
                ],
                array_filter($fields, 'is_array'),
            )),
            'captcha' => ['enabled' => CaptchaVerifier::enabled(), 'site_key' => CaptchaVerifier::siteKey()],
        ]);
    }

    public function store(Request $request, string $uuid, SubmitLeadFormAction $action)
    {
        $message = $action->execute($uuid, $request->all(), $request->ip());

        return response()->json(['message' => $message], 201);
    }
}