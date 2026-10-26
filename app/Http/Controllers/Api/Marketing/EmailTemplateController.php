<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\Actions\CreateEmailTemplateAction;
use App\Domain\Marketing\Actions\DeleteEmailTemplateAction;
use App\Domain\Marketing\Actions\UpdateEmailTemplateAction;
use App\Domain\Marketing\Templates\TemplateRenderer;
use App\Domain\Marketing\Templates\TemplateVariables;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ListEmailTemplatesRequest;
use App\Http\Requests\Marketing\PreviewEmailTemplateRequest;
use App\Http\Requests\Marketing\SaveEmailTemplateRequest;
use App\Http\Resources\EmailTemplateResource;
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
        $user = Auth::user();
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