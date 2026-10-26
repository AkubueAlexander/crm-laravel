# crm-9-3b.ps1 - 9.3b email templates API: actions, requests, resource, controller, routes. Run from the repo root.
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path
if (-not (Test-Path (Join-Path $root 'app\Domain\Marketing\Templates\TemplateRenderer.php'))) {
    throw "9.3a is not applied (TemplateRenderer.php not found). Run crm-9-3a.ps1 first."
}
if (-not (Test-Path (Join-Path $root 'app\Http\Controllers\Controller.php'))) {
    throw "app\Http\Controllers\Controller.php not found."
}

function Write-NoBomAbs {
    param([string]$FullPath, [string]$Content)
    $dir = Split-Path $FullPath -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $text = $Content.Replace("`r`n", "`n")
    [System.IO.File]::WriteAllText($FullPath, $text, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host ("wrote " + $FullPath.Substring($root.Length + 1))
}

# Put the new controller/requests/resource next to the segment ones, whatever namespaces the repo uses.
function Find-Php([string]$FileName) {
    $f = Get-ChildItem (Join-Path $root 'app') -Recurse -Filter $FileName | Select-Object -First 1
    if (-not $f) { throw "Could not find $FileName under app\." }
    $m = Select-String -Path $f.FullName -Pattern '^\s*namespace\s+([^;]+);' | Select-Object -First 1
    if (-not $m) { throw "No namespace in $($f.FullName)" }
    [pscustomobject]@{ Dir = $f.DirectoryName; Ns = $m.Matches[0].Groups[1].Value.Trim() }
}
$ctrl = Find-Php 'SegmentController.php'
$req  = Find-Php 'SaveSegmentRequest.php'
$res  = Find-Php 'SegmentResource.php'
Write-Host "controllers: $($ctrl.Ns) | requests: $($req.Ns) | resources: $($res.Ns)"

function Fill([string]$Text) {
    return $Text.Replace('__CTRL_NS__', $ctrl.Ns).Replace('__REQ_NS__', $req.Ns).Replace('__RES_NS__', $res.Ns)
}

$actions = Join-Path $root 'app\Domain\Marketing\Actions'

# ---------------------------------------------------------------- 1. CreateEmailTemplateAction
Write-NoBomAbs (Join-Path $actions 'CreateEmailTemplateAction.php') @'
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
'@

# ---------------------------------------------------------------- 2. UpdateEmailTemplateAction
Write-NoBomAbs (Join-Path $actions 'UpdateEmailTemplateAction.php') @'
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
'@

# ---------------------------------------------------------------- 3. DeleteEmailTemplateAction
Write-NoBomAbs (Join-Path $actions 'DeleteEmailTemplateAction.php') @'
<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteEmailTemplateAction
{
    public function __invoke(User $actor, int $id): void
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        // TODO(9.4): once campaigns reference a template, refuse deleting one used by a scheduled
        // or sent campaign (same rule as segments).
        DB::transaction(function () use ($actor, $id): void {
            $template = EmailTemplate::query()
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $template->delete();
        });
    }
}
'@

# ---------------------------------------------------------------- 4. ListEmailTemplatesRequest
Write-NoBomAbs (Join-Path $req.Dir 'ListEmailTemplatesRequest.php') (Fill @'
<?php

declare(strict_types=1);

namespace __REQ_NS__;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class ListEmailTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard('web')->user();

        return $user !== null && $user->can('marketing.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['name', 'subject', 'created_at', 'updated_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
'@)

# ---------------------------------------------------------------- 5. SaveEmailTemplateRequest
Write-NoBomAbs (Join-Path $req.Dir 'SaveEmailTemplateRequest.php') (Fill @'
<?php

declare(strict_types=1);

namespace __REQ_NS__;

use App\Domain\Marketing\Templates\TemplateVariables;
use App\Models\EmailTemplate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * UX-level validation. The Create/Update actions validate again and the unique index is the real guard.
 */
final class SaveEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard('web')->user();

        return $user !== null && $user->can('marketing.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = (int) Auth::guard('web')->user()?->tenant_id;
        $routeId = $this->route('template');
        $ignoreId = $routeId !== null ? (int) $routeId : null;

        return [
            'name' => [
                'required', 'string', 'max:'.EmailTemplate::MAX_NAME,
                function (string $attribute, mixed $value, Closure $fail) use ($tenantId, $ignoreId): void {
                    if (! is_string($value)) {
                        return;
                    }
                    $exists = DB::table('email_templates')
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(name) = lower(?)', [trim($value)])
                        ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();
                    if ($exists) {
                        $fail('A template with this name already exists.');
                    }
                },
            ],
            'subject' => ['required', 'string', 'max:'.EmailTemplate::MAX_SUBJECT],
            'body' => ['required', 'string', 'max:'.EmailTemplate::MAX_BODY],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $validator->getData();
                $subject = $data['subject'] ?? null;
                $body = $data['body'] ?? null;
                if (! is_string($subject) || ! is_string($body)) {
                    return;
                }
                $errors = TemplateVariables::errors($subject, $body);
                foreach (['subject', 'body'] as $key) {
                    if ($validator->errors()->has($key)) {
                        continue;
                    }
                    foreach ($errors[$key] ?? [] as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            },
        ];
    }
}
'@)

# ---------------------------------------------------------------- 6. PreviewEmailTemplateRequest
Write-NoBomAbs (Join-Path $req.Dir 'PreviewEmailTemplateRequest.php') (Fill @'
<?php

declare(strict_types=1);

namespace __REQ_NS__;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Preview is lenient on purpose: an empty or half-written template still renders, and unknown
 * variables come back as warnings instead of a 422.
 */
final class PreviewEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard('web')->user();

        return $user !== null && $user->can('marketing.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:'.EmailTemplate::MAX_SUBJECT],
            'body' => ['nullable', 'string', 'max:'.EmailTemplate::MAX_BODY],
        ];
    }
}
'@)

# ---------------------------------------------------------------- 7. EmailTemplateResource
Write-NoBomAbs (Join-Path $res.Dir 'EmailTemplateResource.php') (Fill @'
<?php

declare(strict_types=1);

namespace __RES_NS__;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailTemplate */
final class EmailTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            // The body can be up to 50k characters: list responses leave it out.
            'body' => $this->when(
                $request->routeIs('*email-templates.show', '*email-templates.store', '*email-templates.update'),
                $this->body,
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
'@)

# ---------------------------------------------------------------- 8. EmailTemplateController
Write-NoBomAbs (Join-Path $ctrl.Dir 'EmailTemplateController.php') (Fill @'
<?php

declare(strict_types=1);

namespace __CTRL_NS__;

use App\Domain\Marketing\Actions\CreateEmailTemplateAction;
use App\Domain\Marketing\Actions\DeleteEmailTemplateAction;
use App\Domain\Marketing\Actions\UpdateEmailTemplateAction;
use App\Domain\Marketing\Templates\TemplateRenderer;
use App\Domain\Marketing\Templates\TemplateVariables;
use App\Http\Controllers\Controller;
use __REQ_NS__\ListEmailTemplatesRequest;
use __REQ_NS__\PreviewEmailTemplateRequest;
use __REQ_NS__\SaveEmailTemplateRequest;
use __RES_NS__\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class EmailTemplateController extends Controller
{
    public function index(ListEmailTemplatesRequest $request): AnonymousResourceCollection
    {
        $actor = $this->actor();
        $q = trim((string) $request->validated('q', ''));
        $sort = (string) $request->validated('sort', 'updated_at');
        $direction = (string) $request->validated('direction', 'desc');

        $query = EmailTemplate::query()->where('tenant_id', $actor->tenant_id);

        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('subject', 'ilike', $like));
        }

        return EmailTemplateResource::collection(
            $query->orderBy($sort, $direction)->orderBy('id')->paginate(15)->withQueryString()
        );
    }

    public function show(int $template): EmailTemplateResource
    {
        $actor = $this->actor();
        Gate::forUser($actor)->authorize('marketing.view');

        return EmailTemplateResource::make($this->find($actor, $template));
    }

    public function store(SaveEmailTemplateRequest $request): JsonResponse
    {
        $template = (new CreateEmailTemplateAction())($this->actor(), $request->validated());

        return EmailTemplateResource::make($template)->response()->setStatusCode(201);
    }

    public function update(SaveEmailTemplateRequest $request, int $template): EmailTemplateResource
    {
        return EmailTemplateResource::make(
            (new UpdateEmailTemplateAction())($this->actor(), $template, $request->validated())
        );
    }

    public function destroy(int $template): JsonResponse
    {
        (new DeleteEmailTemplateAction())($this->actor(), $template);

        return response()->json(null, 204);
    }

    /** The variable catalog and limits the editor renders from (the UI never hardcodes them). */
    public function variables(): JsonResponse
    {
        Gate::forUser($this->actor())->authorize('marketing.view');

        return response()->json([
            'data' => TemplateVariables::catalog(),
            'limits' => [
                'name' => EmailTemplate::MAX_NAME,
                'subject' => EmailTemplate::MAX_SUBJECT,
                'body' => EmailTemplate::MAX_BODY,
            ],
        ]);
    }

    /** Renders with sample data through the same renderer the send job uses. Never writes. */
    public function preview(PreviewEmailTemplateRequest $request): JsonResponse
    {
        $subject = (string) ($request->validated('subject') ?? '');
        $body = (string) ($request->validated('body') ?? '');

        $rendered = TemplateRenderer::render($subject, $body, TemplateVariables::SAMPLE);

        return response()->json([
            'data' => [
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'text' => $rendered['text'],
                'unknown_variables' => array_values(array_unique(array_merge(
                    TemplateVariables::unknownIn($subject),
                    TemplateVariables::unknownIn($body),
                ))),
            ],
        ]);
    }

    private function actor(): User
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function find(User $actor, int $id): EmailTemplate
    {
        return EmailTemplate::query()
            ->where('tenant_id', $actor->tenant_id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
'@)

# ---------------------------------------------------------------- 9. routes/email_templates.php
Write-NoBomAbs (Join-Path $root 'routes\email_templates.php') (Fill @'
<?php

declare(strict_types=1);

use __CTRL_NS__\EmailTemplateController;
use Illuminate\Support\Facades\Route;

// 9.3b. Required from routes/api.php INSIDE the authenticated /api/v1 group (next to the segments
// routes), so it inherits that group's prefix and middleware. Static paths come before {template}.
Route::get('email-templates/variables', [EmailTemplateController::class, 'variables'])->name('email-templates.variables');
Route::post('email-templates/preview', [EmailTemplateController::class, 'preview'])->middleware('throttle:60,1')->name('email-templates.preview');
Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
Route::post('email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
Route::get('email-templates/{template}', [EmailTemplateController::class, 'show'])->whereNumber('template')->name('email-templates.show');
Route::put('email-templates/{template}', [EmailTemplateController::class, 'update'])->whereNumber('template')->name('email-templates.update');
Route::delete('email-templates/{template}', [EmailTemplateController::class, 'destroy'])->whereNumber('template')->name('email-templates.destroy');
'@)

# ---------------------------------------------------------------- 10. patch routes/api.php (anchored; throws if it cannot place the line)
$apiPath = Join-Path $root 'routes\api.php'
if (-not (Test-Path $apiPath)) { throw 'routes\api.php not found.' }
$apiRaw = [System.IO.File]::ReadAllText($apiPath)
if ($apiRaw -match 'email_templates\.php') {
    Write-Host 'routes/api.php already requires email_templates.php (skipped)'
} else {
    $crlf = $apiRaw.Contains("`r`n")
    $lines = [System.Collections.Generic.List[string]]($apiRaw.Replace("`r`n", "`n").Split("`n"))
    $last = -1
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match 'SegmentController' -and $lines[$i] -notmatch '^\s*use\s') { $last = $i }
    }
    if ($last -lt 0) { throw 'Could not find a SegmentController route line in routes/api.php to anchor on.' }
    $end = $last
    while ($end -lt $lines.Count -and $lines[$end].TrimEnd() -notmatch ';\s*$') { $end++ }
    if ($end -ge $lines.Count) { throw 'Could not find the end of the last segments route statement.' }
    $indent = [regex]::Match($lines[$last], '^\s*').Value
    $lines.Insert($end + 1, $indent + "require __DIR__ . '/email_templates.php';")
    $lines.Insert($end + 1, $indent + '// 9.3b: email templates (routes/email_templates.php)')
    $out = [string]::Join("`n", $lines)
    if ($crlf) { $out = $out.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($apiPath, $out, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host ("patched routes/api.php: require added after line " + ($end + 1))
}

Write-Host ''
Write-Host 'Done: 9 files written, routes/api.php patched. Next: php artisan route:list --path=email-templates ; php artisan test'
Write-Host 'Referenced but NOT shown to me (untouched): routes/api.php beyond the one inserted require, the Pest helpers in SegmentApiTest.php. API tests for templates are not written yet (they need your tenant/user test helpers).'
