Write-Host "Running apply-8a2-convert-lead-backend.ps1 (v1 - ASCII only, CRLF, BOM)" -ForegroundColor Cyan

$root = "C:\wamp64\www\crm-erp"

# Writes UTF-8 without BOM. Line endings: matches the existing file if it
# already exists (so edits do not flip a whole file's EOL), otherwise LF.
function Write-NoBom {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Content
    )
    $full = Join-Path $root $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }

    $useCrlf = $false
    if (Test-Path $full) {
        $existing = [System.IO.File]::ReadAllText($full)
        if ($existing.Contains("`r`n")) { $useCrlf = $true }
    }

    $normalized = $Content -replace "`r`n", "`n"
    if (-not $normalized.EndsWith("`n")) { $normalized += "`n" }
    if ($useCrlf) { $normalized = $normalized -replace "`n", "`r`n" }

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($full, $normalized, $utf8NoBom)
    Write-Host "Wrote $Path"
}

# ---------------------------------------------------------------------------
# 1. app/Domain/Leads/Exceptions/LeadNotConvertibleException.php (NEW)
# ---------------------------------------------------------------------------
Write-NoBom -Path "app\Domain\Leads\Exceptions\LeadNotConvertibleException.php" -Content @'
<?php

namespace App\Domain\Leads\Exceptions;

use App\Models\Lead;
use RuntimeException;

/**
 * Thrown by ConvertLeadAction when a lead cannot be converted. The controller
 * maps `reason` onto the { message, errors, code } error shape (14.3).
 */
final class LeadNotConvertibleException extends RuntimeException
{
    public const ALREADY_CONVERTED = 'already_converted';

    public const MISSING_LAST_NAME = 'missing_last_name';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly Lead $lead,
    ) {
        parent::__construct($message);
    }

    public static function alreadyConverted(Lead $lead): self
    {
        return new self(
            self::ALREADY_CONVERTED,
            'This lead has already been converted. Refresh to see its latest state.',
            $lead,
        );
    }

    public static function missingLastName(Lead $lead): self
    {
        return new self(
            self::MISSING_LAST_NAME,
            'Add a last name to this lead before converting it. Contacts require one.',
            $lead,
        );
    }
}
'@

# ---------------------------------------------------------------------------
# 2. app/Domain/Leads/Actions/ConvertLeadAction.php (NEW)
# ---------------------------------------------------------------------------
Write-NoBom -Path "app\Domain\Leads\Actions\ConvertLeadAction.php" -Content @'
<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Exceptions\LeadNotConvertibleException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * 8a.2: converts a Lead into a Contact (+ Account when the lead has a company)
 * and a Deal, then stamps the lead as converted. Single entry point; the
 * 'converted' status is written here directly and is deliberately not
 * assignable through StoreLeadRequest/UpdateLeadRequest.
 *
 * Everything runs in one transaction on a row-locked lead, so a double-click
 * or two racing requests cannot create duplicate records: the loser sees an
 * already-converted lead and gets a 409.
 *
 * Duplicate detection (5.0) is intentionally not run: a person already chose
 * to convert this specific lead.
 */
final class ConvertLeadAction
{
    /**
     * @param  array{deal_name?: ?string, amount?: int|float|string|null, expected_close_date?: ?string}  $data
     *
     * @throws LeadNotConvertibleException
     */
    public function execute(User $actor, Lead $lead, array $data = []): Lead
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('leads.convert');

        return DB::transaction(function () use ($actor, $lead, $data): Lead {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($locked->status === 'converted') {
                throw LeadNotConvertibleException::alreadyConverted($locked);
            }

            // contacts.last_name is NOT NULL; leads.last_name is nullable.
            $lastName = trim((string) $locked->last_name);
            if ($lastName === '') {
                throw LeadNotConvertibleException::missingLastName($locked);
            }

            $account = $this->resolveAccount($locked);

            $contact = Contact::create([
                'account_id' => $account?->id,
                'first_name' => $locked->first_name,
                'last_name' => $lastName,
                'email' => $this->blankToNull($locked->email),
                'phone' => $this->blankToNull($locked->phone),
            ]);

            // board_id is deliberately not set: the deals migration reviewed for
            // this chunk has no such column. `state` is left to DealState's
            // configured default (Lead) rather than guessing its stored key.
            $deal = Deal::create([
                'name' => Str::limit($this->dealName($locked, $lastName, $data), 255, ''),
                'owner_id' => $locked->owner_id ?? $actor->id,
                'amount' => $data['amount'] ?? null,
                'expected_close_date' => $data['expected_close_date'] ?? null,
            ]);

            // Written directly (not via request validation): this is the only
            // place a lead becomes 'converted'.
            $locked->forceFill([
                'status' => 'converted',
                'converted_at' => now(),
                'converted_contact_id' => $contact->id,
                'converted_account_id' => $account?->id,
                'converted_deal_id' => $deal->id,
            ])->save();

            return $locked;
        });
    }

    /** Reuses a same-named account in the current tenant, otherwise creates one. */
    private function resolveAccount(Lead $lead): ?Account
    {
        $company = trim((string) $lead->company);

        if ($company === '') {
            return null;
        }

        // The BelongsToTenant global scope keeps this lookup inside the tenant.
        $existing = Account::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($company)])
            ->first();

        return $existing ?? Account::create([
            'name' => $company,
            'owner_id' => $lead->owner_id,
        ]);
    }

    /** @param  array{deal_name?: ?string}  $data */
    private function dealName(Lead $lead, string $lastName, array $data): string
    {
        $custom = trim((string) ($data['deal_name'] ?? ''));

        if ($custom !== '') {
            return $custom;
        }

        $person = trim($lead->first_name.' '.$lastName);
        $company = trim((string) $lead->company);

        return $company !== '' ? $company.' - '.$person : $person;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
'@

# ---------------------------------------------------------------------------
# 3. app/Http/Requests/Leads/ConvertLeadRequest.php (NEW)
# ---------------------------------------------------------------------------
Write-NoBom -Path "app\Http\Requests\Leads\ConvertLeadRequest.php" -Content @'
<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

/**
 * All fields optional: they only shape the Deal created by the conversion.
 * Zod on the frontend is UX; this is the boundary (14.2).
 */
final class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leads.convert') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'deal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
'@

# ---------------------------------------------------------------------------
# 4. app/Models/Lead.php (EDIT - adds convertedDeal() relation)
#    Full-file overwrite of the version you pasted; only change is the
#    convertedDeal() method at the bottom.
# ---------------------------------------------------------------------------
Write-NoBom -Path "app\Models\Lead.php" -Content @'
<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'source',
        'status',
        'owner_id',
        'converted_at',
        'converted_contact_id',
        'converted_account_id',
        'converted_deal_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'converted_account_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }
}
'@

# ---------------------------------------------------------------------------
# 5. app/Http/Controllers/Api/Leads/LeadController.php (EDIT - adds convert())
#    Full-file overwrite of the version you pasted; existing methods are
#    unchanged. New: 3 imports + convert().
# ---------------------------------------------------------------------------
Write-NoBom -Path "app\Http\Controllers\Api\Leads\LeadController.php" -Content @'
<?php

namespace App\Http\Controllers\Api\Leads;

use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\Exceptions\LeadNotConvertibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\ConvertLeadRequest;
use App\Http\Requests\Leads\ListLeadsRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class LeadController extends Controller
{
    public function index(ListLeadsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim($v['q'] ?? '');

        $leads = Lead::query()
            ->with('owner:id,name')
            ->when($q !== '', function ($query) use ($q) {

                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('company', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like);
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return LeadResource::collection($leads);
    }

    public function show(Lead $lead): LeadResource
    {
        Gate::authorize('leads.view');

        return $this->present($lead);
    }

    public function store(StoreLeadRequest $request, CreateLeadAction $action): LeadResource
    {
        return $this->present($action->execute($request->user(), $request->validated()));
    }

    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLeadAction $action): LeadResource
    {
        return $this->present($action->execute($request->user(), $lead, $request->validated()));
    }

    public function destroy(Request $request, Lead $lead, DeleteLeadAction $action): Response
    {
        $action->execute($request->user(), $lead);

        return response()->noContent();
    }

    /**
     * 8a.2: POST /api/v1/leads/{lead}/convert - the single conversion entry point.
     * Same error shape as the Deals transition endpoint (14.3): 409 when the lead
     * was already converted (carries the current server-side lead), 422 when it
     * cannot be converted yet (e.g. no last name).
     */
    public function convert(ConvertLeadRequest $request, Lead $lead, ConvertLeadAction $action): LeadResource|JsonResponse
    {
        try {
            $converted = $action->execute($request->user(), $lead, $request->validated());
        } catch (LeadNotConvertibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'code' => $e->reason,
                'current' => new LeadResource($e->lead->load('owner:id,name')),
            ], $e->reason === LeadNotConvertibleException::ALREADY_CONVERTED ? 409 : 422);
        }

        return $this->present($converted);
    }


    private function present(Lead $lead): LeadResource
    {
        return new LeadResource($lead->load('owner:id,name'));
    }
}
'@

# ---------------------------------------------------------------------------
# 6. routes/api.php (EDIT - adds POST leads/{lead}/convert)
#    Full-file overwrite of the version you pasted; only change is the
#    convert route, registered just before apiResource('leads').
# ---------------------------------------------------------------------------
Write-NoBom -Path "routes\api.php" -Content @'
<?php

use App\Http\Controllers\Api\Accounts\AccountController;
use App\Http\Controllers\Api\Accounts\AccountOptionsController;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Contacts\ContactController;
use App\Http\Controllers\Api\Contacts\ContactMatchSettingsController;
use App\Http\Controllers\Api\Deals\DealsController;
use App\Http\Controllers\Api\Forecasting\ForecastController;
use App\Http\Controllers\Api\Leads\LeadController;
use App\Http\Controllers\Api\Users\UserOptionsController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 2.1: unauthenticated - login only needs the CSRF cookie already set.
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login');

    Route::middleware(['auth:sanctum', 'resolve.tenant'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');


        Route::get('/me', CurrentUserController::class)->name('me');

        Route::post('deals/{deal}/transition', [DealsController::class, 'transition']);

        Route::get('deals', [DealsController::class, 'index']);

        Broadcast::routes(['middleware' => []]);

        Route::get('forecast', ForecastController::class);

        Route::post('contacts/check-duplicates', [ContactController::class, 'checkDuplicates'])
            ->middleware('throttle:60,1');


        Route::get('contacts/match-settings', [ContactMatchSettingsController::class, 'show']);

        Route::put('contacts/match-settings', [ContactMatchSettingsController::class, 'update']);


        Route::apiResource('contacts', ContactController::class);


        Route::get('accounts/options', AccountOptionsController::class);

        Route::apiResource('accounts', AccountController::class);

        // Owner-select source: {id, name} of the actor's own tenant only.
        Route::get('users/options', UserOptionsController::class);


        // 8a.2: lead conversion (Contact + Account + Deal), single entry point.
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);

        Route::apiResource('leads', LeadController::class);
    });

});
'@

# ---------------------------------------------------------------------------
# 7. database/seeders/DevTenantSeeder.php (EDIT - adds leads.convert)
#    Full-file overwrite of the version you pasted; only change is the new
#    'leads.convert' entry in PERMISSIONS.
# ---------------------------------------------------------------------------
Write-NoBom -Path "database\seeders\DevTenantSeeder.php" -Content @'
<?php

namespace Database\Seeders;

use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;


class DevTenantSeeder extends Seeder
{
    public const PERMISSIONS = [
        'contacts.view',
        'contacts.create',
        'contacts.update',
        'contacts.delete',
        'deals.view',
        'forecast.view',
        'accounts.view',
        'accounts.create',
        'accounts.update',
        'accounts.delete',
        'leads.view',
        'leads.create',
        'leads.update',
        'leads.delete',
        'leads.convert',
    ];

    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme'],
        );

        TenantContext::set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);


        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]),
        );

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $adminRole->syncPermissions($permissions);

        $user = User::updateOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'name' => 'Admin',
                'tenant_id' => $tenant->id,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        if (! $user->hasRole('admin')) {
            $user->assignRole($adminRole);
        }

        TenantContext::clear();

        $this->command?->info("Dev tenant ready: {$tenant->name} (id {$tenant->id})");
        $this->command?->info("Login: admin@acme.test / password");
    }
}
'@

# ---------------------------------------------------------------------------
# 8. tests/Feature/Leads/ConvertLeadTest.php (NEW)
#    Self-contained helpers (convertActor / convertibleLead) so this file
#    passes when run on its own, not only after LeadApiTest.php is loaded.
# ---------------------------------------------------------------------------
Write-NoBom -Path "tests\Feature\Leads\ConvertLeadTest.php" -Content @'
<?php

use App\Domain\Deals\States\Lead as DealLeadState;
use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
});

/** Creates a user on $tenant with the given abilities and acts as them. */
function convertActor(Tenant $tenant, array $abilities = ['leads.view', 'leads.convert']): User
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($abilities as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $user->forgetCachedPermissions();

    Sanctum::actingAs($user);

    return $user;
}

/** A convertible lead on $tenant; every field the action reads is explicit. */
function convertibleLead(Tenant $tenant, array $attrs = []): Lead
{
    TenantContext::set($tenant);

    return Lead::factory()->create(array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'phone' => '555-0100',
        'company' => 'Analytical Engines Ltd',
        'status' => 'qualified',
        'owner_id' => null,
    ], $attrs));
}

test('converting a lead creates a contact, an account and a deal and marks the lead converted', function () {
    convertActor($this->tenantA);
    $owner = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $lead = convertibleLead($this->tenantA, ['owner_id' => $owner->id]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    $response->assertJsonPath('status', 'converted');

    $contactId = $response->json('converted_contact_id');
    $accountId = $response->json('converted_account_id');
    $dealId = $response->json('converted_deal_id');

    expect($contactId)->not->toBeNull();
    expect($accountId)->not->toBeNull();
    expect($dealId)->not->toBeNull();

    $this->assertDatabaseHas('accounts', [
        'id' => $accountId,
        'name' => 'Analytical Engines Ltd',
        'owner_id' => $owner->id,
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseHas('contacts', [
        'id' => $contactId,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'account_id' => $accountId,
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseHas('deals', [
        'id' => $dealId,
        'name' => 'Analytical Engines Ltd - Ada Lovelace',
        'owner_id' => $owner->id,
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseHas('leads', [
        'id' => $lead->id,
        'status' => 'converted',
        'converted_contact_id' => $contactId,
        'converted_account_id' => $accountId,
        'converted_deal_id' => $dealId,
    ]);
    expect(DB::table('leads')->where('id', $lead->id)->value('converted_at'))->not->toBeNull();
});

test('the deal created by conversion starts in the default lead stage', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $dealId = $this->postJson("/api/v1/leads/{$lead->id}/convert")->json('converted_deal_id');

    $deal = Deal::withoutGlobalScopes()->findOrFail($dealId);

    expect($deal->state)->toBeInstanceOf(DealLeadState::class);
    expect($deal->lock_version)->toBe(0);
});

test('an unowned lead produces a deal owned by the user who converted it', function () {
    $actor = convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['owner_id' => null]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    $this->assertDatabaseHas('deals', [
        'id' => $response->json('converted_deal_id'),
        'owner_id' => $actor->id,
    ]);
    $this->assertDatabaseHas('accounts', [
        'id' => $response->json('converted_account_id'),
        'owner_id' => null,
    ]);
});

test('optional deal fields shape the created deal', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert", [
        'deal_name' => 'Big Rollout',
        'amount' => '12500.50',
        'expected_close_date' => '2026-12-31',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('deals', [
        'id' => $response->json('converted_deal_id'),
        'name' => 'Big Rollout',
        'amount' => '12500.50',
        'expected_close_date' => '2026-12-31',
    ]);
});

test('an existing account with the same company name is reused, case-insensitively', function () {
    convertActor($this->tenantA);
    $existing = Account::create(['name' => 'ANALYTICAL ENGINES LTD']);
    $lead = convertibleLead($this->tenantA, ['company' => 'Analytical Engines Ltd']);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    expect($response->json('converted_account_id'))->toBe($existing->id);
    expect(DB::table('accounts')->where('tenant_id', $this->tenantA->id)->count())->toBe(1);
    $this->assertDatabaseHas('contacts', [
        'id' => $response->json('converted_contact_id'),
        'account_id' => $existing->id,
    ]);
});

test('a lead without a company converts without creating an account', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['company' => null]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    expect($response->json('converted_account_id'))->toBeNull();
    $this->assertDatabaseHas('contacts', [
        'id' => $response->json('converted_contact_id'),
        'account_id' => null,
    ]);
    $this->assertDatabaseHas('deals', [
        'id' => $response->json('converted_deal_id'),
        'name' => 'Ada Lovelace',
    ]);
    expect(DB::table('accounts')->count())->toBe(0);
});

test('an account with the same name in another tenant is never reused', function () {
    TenantContext::set($this->tenantB);
    $foreign = Account::create(['name' => 'Analytical Engines Ltd']);

    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    expect($response->json('converted_account_id'))->not->toBe($foreign->id);
    $this->assertDatabaseHas('accounts', [
        'id' => $response->json('converted_account_id'),
        'tenant_id' => $this->tenantA->id,
    ]);
});

test('converting an already converted lead returns 409 and creates nothing', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['status' => 'converted', 'converted_at' => now()]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'already_converted');
    $response->assertJsonPath('current.status', 'converted');
    expect(DB::table('contacts')->count())->toBe(0);
    expect(DB::table('deals')->count())->toBe(0);
    expect(DB::table('accounts')->count())->toBe(0);
});

test('a lead without a last name cannot be converted and nothing is created', function (?string $lastName) {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['last_name' => $lastName]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'missing_last_name');
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified']);
    expect(DB::table('contacts')->count())->toBe(0);
    expect(DB::table('deals')->count())->toBe(0);
    expect(DB::table('accounts')->count())->toBe(0);
})->with([
    'null last name' => [null],
    'blank last name' => ['   '],
]);

test('a user without leads.convert cannot convert a lead', function () {
    convertActor($this->tenantA, abilities: ['leads.view']);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertForbidden();
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified']);
    expect(DB::table('contacts')->count())->toBe(0);
});

test('a user cannot convert another tenant lead', function () {
    $foreignLead = convertibleLead($this->tenantB);
    convertActor($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$foreignLead->id}/convert");

    $response->assertNotFound();
    $this->assertDatabaseHas('leads', ['id' => $foreignLead->id, 'status' => 'qualified']);
});

test('an invalid deal amount is rejected and the lead stays unconverted', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert", ['amount' => -5]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('amount');
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified']);
});
'@

Write-Host ""
Write-Host "Done. 8 files written." -ForegroundColor Green
Write-Host "NOT touched / not shown to me: LeadResource (assumed to expose the three converted_*_id fields per the handover), CreateLead/UpdateLead/DeleteLeadAction, DealResource, DealStageAuditLog (no initial audit row is written for the new deal), and any follow-up migration that may add deals.board_id." -ForegroundColor Yellow
