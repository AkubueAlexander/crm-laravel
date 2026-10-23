$ErrorActionPreference = 'Stop'
Write-Host 'crm-erp 8b.3 contact import backend v1'

$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom {
    param([string]$Path, [string]$Content)
    $full = Join-Path (Get-Location).Path $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $useCrlf = $false
    if (Test-Path $full) {
        $existing = [System.IO.File]::ReadAllText($full)
        if ($existing.Contains("`r`n")) { $useCrlf = $true }
    }
    $text = $Content -replace "`r`n", "`n"
    $text = $text.TrimEnd("`n") + "`n"
    if ($useCrlf) { $text = $text -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $text, $Utf8NoBom)
    Write-Host "  wrote $Path"
}

function Patch-Once {
    param([string]$Path, [string]$Old, [string]$New, [string]$Marker)
    $full = Join-Path (Get-Location).Path $Path
    if (-not (Test-Path $full)) { throw "Patch target not found: $Path" }
    $raw = [System.IO.File]::ReadAllText($full)
    $crlf = $raw.Contains("`r`n")
    $norm = $raw -replace "`r`n", "`n"
    if ($norm.Contains($Marker)) { Write-Host "  skip $Path (already patched: $Marker)"; return }
    $oldN = $Old -replace "`r`n", "`n"
    $newN = $New -replace "`r`n", "`n"
    $count = ([regex]::Matches($norm, [regex]::Escape($oldN))).Count
    if ($count -ne 1) { throw "Anchor in $Path matched $count times (expected 1): $oldN" }
    $norm = $norm.Replace($oldN, $newN)
    if ($crlf) { $norm = $norm -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $norm, $Utf8NoBom)
    Write-Host "  patched $Path"
}

foreach ($req in @('app\Domain\Contacts\Actions\CreateContactAction.php', 'app\Domain\Contacts\Matching\DuplicateDetectionService.php', 'routes\api.php')) {
    if (-not (Test-Path $req)) { throw "Missing prerequisite: $req (run from the repo root)" }
}
$stale = Get-ChildItem 'database\migrations' -File | Where-Object { $_.Name -match 'create_contact_imports_table' -and $_.Name -ne '2026_10_23_120000_create_contact_imports_table.php' }
if ($stale) { throw "A different contact_imports migration already exists: $($stale.Name). Remove it first." }

# ---------------------------------------------------------------- 1. Migration
Write-NoBom 'database\migrations\2026_10_23_120000_create_contact_imports_table.php' @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            // Capped list of skipped rows ({row, type, message}); counters stay exact beyond the cap.
            $table->jsonb('report')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at'], 'contact_imports_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};
'@

# ---------------------------------------------------------------- 2. Model
Write-NoBom 'app\Models\ContactImport.php' @'
<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImport extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Uploaded files live on a private disk and are deleted once the import finishes. */
    public const DISK = 'local';

    // tenant_id is deliberately NOT fillable; BelongsToTenant stamps it from context (1.3).
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'status',
        'total_rows',
        'processed_rows',
        'created_rows',
        'duplicate_rows',
        'invalid_rows',
        'report',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
'@

# ---------------------------------------------------------------- 3. CSV reader
Write-NoBom 'app\Domain\Contacts\Import\ContactCsvReader.php' @'
<?php

namespace App\Domain\Contacts\Import;

use Generator;
use InvalidArgumentException;

/**
 * Streams a contacts CSV one row at a time (never loads the whole file).
 * Tolerant of a UTF-8 BOM, Windows-1252 bytes, comma or semicolon delimiters,
 * common header spellings, and blank lines. Row numbers are the file's own
 * record numbers (header = 1) so error reports match what the user sees in Excel.
 */
final class ContactCsvReader
{
    public const MAX_ROWS = 5000;

    private const ALIASES = [
        'first_name' => ['first_name', 'firstname', 'first', 'given_name'],
        'last_name' => ['last_name', 'lastname', 'last', 'surname', 'family_name'],
        'email' => ['email', 'email_address', 'e_mail'],
        'phone' => ['phone', 'phone_number', 'mobile', 'telephone', 'tel'],
        'job_title' => ['job_title', 'jobtitle', 'title', 'position'],
    ];

    /**
     * Cheap synchronous pre-check used before queueing.
     *
     * @return int number of data rows
     *
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function inspect(string $path): int
    {
        $total = 0;

        foreach (self::rows($path) as $unused) {
            $total++;

            if ($total > self::MAX_ROWS) {
                throw new InvalidArgumentException(
                    'The CSV has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.'
                );
            }
        }

        if ($total === 0) {
            throw new InvalidArgumentException('The CSV has no data rows.');
        }

        return $total;
    }

    /**
     * @return Generator<int, array{0: int, 1: array<string, string|null>}> [rowNumber, fields]
     *
     * @throws InvalidArgumentException
     */
    public static function rows(string $path): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException('The CSV file could not be read.');
        }

        try {
            $firstLine = fgets($handle);

            if ($firstLine === false) {
                throw new InvalidArgumentException('The CSV file is empty.');
            }

            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);

            $header = fgetcsv($handle, null, $delimiter, '"', '');
            $map = self::mapHeader(is_array($header) ? $header : []);

            if (! isset($map['last_name'])) {
                throw new InvalidArgumentException('The CSV needs a "last_name" column (or "surname").');
            }

            $rowNumber = 1;

            while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $rowNumber++;

                if (self::isBlank($record)) {
                    continue;
                }

                $fields = [];
                foreach ($map as $field => $index) {
                    $value = $record[$index] ?? null;
                    $value = $value === null ? '' : trim(self::utf8($value));
                    $fields[$field] = $value === '' ? null : $value;
                }

                yield [$rowNumber, $fields];
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $header @return array<string, int> field => column index */
    private static function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $name = strtolower(trim(ltrim(self::utf8((string) $cell), "\xEF\xBB\xBF")));
            $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');

            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    /** @param list<string|null> $record */
    private static function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if ($cell !== null && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
'@

# ---------------------------------------------------------------- 4. Event (6.0 pattern, same private user channel as 8b.2)
Write-NoBom 'app\Events\ContactImportProgress.php' @'
<?php

namespace App\Events;

use App\Models\ContactImport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed on the tenant-scoped private user channel authorized by UserChannel (1.5).
 * Broadcast immediately: the job that raises it is already running on a queue worker.
 * The client treats it as a progress signal and re-reads the import through the API.
 */
final class ContactImportProgress implements ShouldBroadcastNow
{
    use Dispatchable;

    public readonly int $tenantId;

    public readonly int $userId;

    /** @var array<string, int|string> */
    public readonly array $payload;

    public function __construct(ContactImport $import)
    {
        $this->tenantId = (int) $import->tenant_id;
        $this->userId = (int) $import->user_id;
        $this->payload = [
            'id' => (int) $import->id,
            'status' => (string) $import->status,
            'total_rows' => (int) $import->total_rows,
            'processed_rows' => (int) $import->processed_rows,
            'created_rows' => (int) $import->created_rows,
            'duplicate_rows' => (int) $import->duplicate_rows,
            'invalid_rows' => (int) $import->invalid_rows,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'contact-import.progress';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
'@

# ---------------------------------------------------------------- 5. Job
Write-NoBom 'app\Jobs\ImportContactsJob.php' @'
<?php

namespace App\Jobs;

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Import\ContactCsvReader;
use App\Domain\Contacts\Matching\ContactCandidate;
use App\Domain\Contacts\Matching\DuplicateDetectionService;
use App\Domain\Tenant\TenantContext;
use App\Events\ContactImportProgress;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Models\ContactImport;
use App\Models\ContactMatchSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * 8b.3: heavy lifting stays server-side and queued (a large CSV scored inline would
 * time out an HTTP request). Runs AS the importing user: contacts.create is re-checked
 * through CreateContactAction, rows are validated with StoreContactRequest's own rules,
 * and likely duplicates (5.0) are skipped and reported rather than created.
 */
class ImportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    private const FLUSH_EVERY = 25;

    private const REPORT_CAP = 200;

    private bool $broadcastBroken = false;

    public function __construct(public readonly int $importId)
    {
    }

    public function handle(CreateContactAction $create, DuplicateDetectionService $duplicates): void
    {
        // System work: bypass the tenant scope explicitly; tenant context is set from the row below.
        $import = ContactImport::withoutTenantScope()->find($this->importId);

        // Idempotent: a retry, a double dispatch or a finished import is a no-op.
        if ($import === null || $import->status !== ContactImport::STATUS_PENDING) {
            return;
        }

        $user = User::query()->find($import->user_id);
        $tenant = $user?->tenant;

        if ($user === null || $tenant === null || (int) $tenant->id !== (int) $import->tenant_id) {
            $this->markFailed($import, 'The user who started this import is no longer available.');

            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $this->process($import, $user, $create, $duplicates);
        } catch (AuthorizationException) {
            $this->markFailed($import, 'You no longer have permission to import contacts.');
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($import, 'The import stopped unexpectedly. Rows already imported were kept.');
        } finally {
            TenantContext::clear();
        }
    }

    /** Timeouts and worker crashes land here; handle()'s catch does not run for those. */
    public function failed(Throwable $e): void
    {
        $import = ContactImport::withoutTenantScope()->find($this->importId);

        if ($import !== null && in_array($import->status, [ContactImport::STATUS_PENDING, ContactImport::STATUS_PROCESSING], true)) {
            $this->markFailed($import, 'The import was interrupted. Rows already imported were kept.');
        }
    }

    private function process(
        ContactImport $import,
        User $user,
        CreateContactAction $create,
        DuplicateDetectionService $duplicates,
    ): void {
        // Fail fast if the permission was revoked while the job waited in the queue.
        Gate::forUser($user)->authorize('contacts.create');

        $import->update(['status' => ContactImport::STATUS_PROCESSING, 'started_at' => now()]);
        $this->broadcast($import);

        // Same rules as the single-contact form (14.2); account_id is not importable.
        $rules = Arr::except((new StoreContactRequest())->rules(), ['account_id']);

        // Explicit tenant predicate: queue workers are console processes, so the
        // threshold lookup must not depend on the global scope being active.
        $threshold = (int) (ContactMatchSettings::query()
            ->where('tenant_id', $import->tenant_id)
            ->value('match_threshold') ?? ContactMatchSettings::DEFAULT_THRESHOLD);

        $path = Storage::disk(ContactImport::DISK)->path($import->path);
        $report = [];

        foreach (ContactCsvReader::rows($path) as [$rowNumber, $data]) {
            $import->processed_rows++;

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                $import->invalid_rows++;
                $this->note($report, [
                    'row' => $rowNumber,
                    'type' => 'invalid',
                    'message' => $validator->errors()->first(),
                ]);
            } else {
                $matches = $duplicates->findDuplicates(
                    new ContactCandidate(
                        $data['first_name'] ?? null,
                        $data['last_name'] ?? null,
                        $data['email'] ?? null,
                        $data['phone'] ?? null,
                    ),
                    null,
                    $threshold,
                );

                if ($matches->isNotEmpty()) {
                    $best = $matches->first();
                    $import->duplicate_rows++;
                    $this->note($report, [
                        'row' => $rowNumber,
                        'type' => 'duplicate',
                        'message' => sprintf('Possible duplicate (%d%% match: %s)', $best->score->score, implode(', ', $best->score->reasons)),
                        'matched_contact_id' => $best->contact->id,
                    ]);
                } else {
                    $create->execute($user, Arr::only($data, ['first_name', 'last_name', 'email', 'phone', 'job_title']));
                    $import->created_rows++;
                }
            }

            if ($import->processed_rows % self::FLUSH_EVERY === 0) {
                $this->flush($import, $report);
            }
        }

        $import->status = ContactImport::STATUS_COMPLETED;
        $import->finished_at = now();
        $this->flush($import, $report);
        $this->cleanup($import);
    }

    /** @param list<array<string, mixed>> $report */
    private function note(array &$report, array $entry): void
    {
        if (count($report) < self::REPORT_CAP) {
            $report[] = $entry;
        }
    }

    /** @param list<array<string, mixed>> $report */
    private function flush(ContactImport $import, array $report): void
    {
        $import->report = $report;
        $import->save();
        $this->broadcast($import);
    }

    private function markFailed(ContactImport $import, string $message): void
    {
        $import->update([
            'status' => ContactImport::STATUS_FAILED,
            'error' => $message,
            'finished_at' => now(),
        ]);

        $this->broadcast($import);
        $this->cleanup($import);
    }

    /** A broadcast outage must never stop the import, so the first failure silences the rest. */
    private function broadcast(ContactImport $import): void
    {
        if ($this->broadcastBroken) {
            return;
        }

        try {
            event(new ContactImportProgress($import));
        } catch (Throwable $e) {
            $this->broadcastBroken = true;
            report($e);
        }
    }

    private function cleanup(ContactImport $import): void
    {
        try {
            Storage::disk(ContactImport::DISK)->delete($import->path);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
'@

# ---------------------------------------------------------------- 6. Action
Write-NoBom 'app\Domain\Contacts\Actions\StartContactImportAction.php' @'
<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Contacts\Import\ContactCsvReader;
use App\Jobs\ImportContactsJob;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class StartContactImportAction
{
    public function execute(User $actor, UploadedFile $file): ContactImport
    {
        Gate::forUser($actor)->authorize('contacts.create');

        // Reject a bad header or an oversized file synchronously, with a clear message,
        // instead of failing silently later on a queue worker.
        try {
            $total = ContactCsvReader::inspect((string) $file->getRealPath());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        $path = $file->storeAs("contact-imports/{$actor->tenant_id}", Str::uuid()->toString().'.csv', ContactImport::DISK);

        if ($path === false) {
            throw new RuntimeException('The CSV could not be stored.');
        }

        // tenant_id is stamped from the request's tenant context by BelongsToTenant.
        $import = ContactImport::create([
            'user_id' => $actor->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'status' => ContactImport::STATUS_PENDING,
            'total_rows' => $total,
        ]);

        ImportContactsJob::dispatch($import->id);

        return $import;
    }
}
'@

# ---------------------------------------------------------------- 7. Request
Write-NoBom 'app\Http\Requests\Contacts\StoreContactImportRequest.php' @'
<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.create') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:5120',
                'extensions:csv,txt',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
            ],
        ];
    }
}
'@

# ---------------------------------------------------------------- 8. Resource
Write-NoBom 'app\Http\Resources\ContactImportResource.php' @'
<?php

namespace App\Http\Resources;

use App\Models\ContactImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactImport */
class ContactImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'created_rows' => $this->created_rows,
            'duplicate_rows' => $this->duplicate_rows,
            'invalid_rows' => $this->invalid_rows,
            'report' => $this->report ?? [],
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@

# ---------------------------------------------------------------- 9. Controller
Write-NoBom 'app\Http\Controllers\Api\Contacts\ContactImportController.php' @'
<?php

namespace App\Http\Controllers\Api\Contacts;

use App\Domain\Contacts\Actions\StartContactImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactImportRequest;
use App\Http\Resources\ContactImportResource;
use App\Models\ContactImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ContactImportController extends Controller
{
    public function store(StoreContactImportRequest $request, StartContactImportAction $action): JsonResponse
    {
        $import = $action->execute($request->user(), $request->file('file'));

        // 202: accepted for background processing; progress arrives over the user channel.
        return (new ContactImportResource($import))->response()->setStatusCode(202);
    }

    public function show(Request $request, ContactImport $import): ContactImportResource
    {
        Gate::authorize('contacts.create');

        // Tenant scoping already 404s another tenant's import; an import is also private to its starter.
        abort_unless((int) $import->user_id === (int) $request->user()->id, 404);

        return new ContactImportResource($import);
    }
}
'@

# ---------------------------------------------------------------- 10. Tests
Write-NoBom 'tests\Feature\Contacts\ContactImportTest.php' @'
<?php

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Import\ContactCsvReader;
use App\Domain\Contacts\Matching\DuplicateDetectionService;
use App\Domain\Tenant\TenantContext;
use App\Events\ContactImportProgress;
use App\Jobs\ImportContactsJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function cimpTenant(string $slug)
{
    $class = class_exists(\App\Models\Tenant::class)
        ? \App\Models\Tenant::class
        : \App\Domain\Tenant\Tenant::class;

    return $class::create(['name' => $slug, 'slug' => $slug]);
}

function cimpSwitch($tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function cimpUser($tenant, bool $canCreate = true): User
{
    cimpSwitch($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    if ($canCreate) {
        Permission::findOrCreate('contacts.create');
        $user->givePermissionTo('contacts.create');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param list<list<string>> $rows */
function cimpCsv(array $rows): string
{
    $lines = ['first_name,last_name,email,phone'];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return implode("\n", $lines)."\n";
}

function cimpUpload(User $user, string $csv, string $name = 'contacts.csv')
{
    Sanctum::actingAs($user);

    return test()->post(
        '/api/v1/contacts/imports',
        ['file' => UploadedFile::fake()->createWithContent($name, $csv)],
        ['Accept' => 'application/json'],
    );
}

function cimpRun(int $importId): void
{
    (new ImportContactsJob($importId))->handle(
        app(CreateContactAction::class),
        app(DuplicateDetectionService::class),
    );
}

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
    Storage::fake('local');
});

it('reads aliases, a BOM, semicolons and skips blank rows', function () {
    $path = tempnam(sys_get_temp_dir(), 'cimp');
    file_put_contents($path, "\xEF\xBB\xBFSurname;First Name;E-mail\nSmith;Jo;jo@example.com\n\n;;\nLee;;\n");

    $rows = iterator_to_array(ContactCsvReader::rows($path), false);
    unlink($path);

    expect($rows)->toHaveCount(2);
    expect($rows[0][0])->toBe(2);
    expect($rows[0][1])->toEqual(['last_name' => 'Smith', 'first_name' => 'Jo', 'email' => 'jo@example.com']);
    expect($rows[1][0])->toBe(5);
    expect($rows[1][1]['last_name'])->toBe('Lee');
    expect($rows[1][1]['first_name'])->toBeNull();
});

it('accepts an upload and queues the import', function () {
    Queue::fake();
    $user = cimpUser(cimpTenant('cimp-a'));

    cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('total_rows', 1);

    Queue::assertPushed(ImportContactsJob::class);
});

it('rejects a csv without a last name column before queueing', function () {
    Queue::fake();
    $user = cimpUser(cimpTenant('cimp-a'));

    cimpUpload($user, "email,phone\na@example.com,5551234567\n")
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    Queue::assertNothingPushed();
});

it('forbids users without contacts.create', function () {
    Queue::fake();
    $user = cimpUser(cimpTenant('cimp-a'), false);

    cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->assertForbidden();

    Queue::assertNothingPushed();
});

it('creates valid rows and skips invalid and duplicate rows with a report', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);
    Contact::create(['first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com']);

    $id = cimpUpload($user, cimpCsv([
        ['Bob', 'Jones', 'bob@example.com', '555-123-4567'],
        ['', '', 'nolast@example.com', ''],
        ['Jane', 'Smith', 'jane@example.com', ''],
        ['Ann', 'Lee', '', ''],
    ]))->assertStatus(202)->json('id');

    cimpRun($id);
    cimpSwitch($tenant);

    $import = ContactImport::find($id);

    expect($import->status)->toBe('completed');
    expect($import->processed_rows)->toBe(4);
    expect($import->created_rows)->toBe(2);
    expect($import->invalid_rows)->toBe(1);
    expect($import->duplicate_rows)->toBe(1);
    expect(collect($import->report)->pluck('type')->sort()->values()->all())->toBe(['duplicate', 'invalid']);
    expect(Contact::count())->toBe(3);
    Storage::disk('local')->assertMissing($import->path);
});

it('is a no-op when the same import runs twice', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);

    $id = cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    cimpRun($id);
    cimpRun($id);
    cimpSwitch($tenant);

    expect(Contact::count())->toBe(1);
    expect(ContactImport::find($id)->created_rows)->toBe(1);
});

it('does not treat another tenants contact as a duplicate', function () {
    Queue::fake();
    $a = cimpTenant('cimp-a');
    $b = cimpTenant('cimp-b');

    cimpSwitch($b);
    Contact::create(['first_name' => 'Zed', 'last_name' => 'Quill', 'email' => 'shared@example.com']);

    $user = cimpUser($a);
    $id = cimpUpload($user, cimpCsv([['Zed', 'Quill', 'shared@example.com', '']]))->json('id');

    cimpRun($id);
    cimpSwitch($a);

    expect(ContactImport::find($id)->created_rows)->toBe(1);
    expect(ContactImport::find($id)->duplicate_rows)->toBe(0);
    expect(Contact::count())->toBe(1);
});

it('shows an import only to the user who started it', function () {
    Queue::fake();
    $a = cimpTenant('cimp-a');
    $b = cimpTenant('cimp-b');
    $owner = cimpUser($a);
    $colleague = cimpUser($a);
    $stranger = cimpUser($b);

    $id = cimpUpload($owner, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/contacts/imports/{$id}")->assertOk()->assertJsonPath('status', 'pending');

    Sanctum::actingAs($colleague);
    $this->getJson("/api/v1/contacts/imports/{$id}")->assertNotFound();

    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/contacts/imports/{$id}")->assertNotFound();
});

it('broadcasts progress on the tenant-scoped private user channel', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);
    $id = cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    Event::fake([ContactImportProgress::class]);
    cimpRun($id);

    Event::assertDispatched(ContactImportProgress::class, function ($event) use ($tenant, $user, $id) {
        $names = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        return $event->payload['status'] === 'completed'
            && $event->payload['id'] === $id
            && in_array("private-tenant.{$tenant->id}.user.{$user->id}", $names, true);
    });
});

it('fails cleanly when the permission was revoked while queued', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);
    $id = cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    cimpSwitch($tenant);
    $user->revokePermissionTo('contacts.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    cimpRun($id);
    cimpSwitch($tenant);

    $import = ContactImport::find($id);
    expect($import->status)->toBe('failed');
    expect($import->error)->toContain('permission');
    expect(Contact::count())->toBe(0);
});
'@

# ---------------------------------------------------------------- 11. Routes (api.php): before the contacts apiResource
Patch-Once -Path 'routes\api.php' `
    -Old 'use App\Http\Controllers\Api\Contacts\ContactController;' `
    -New ('use App\Http\Controllers\Api\Contacts\ContactController;' + "`n" + 'use App\Http\Controllers\Api\Contacts\ContactImportController;') `
    -Marker 'ContactImportController;'

$routeNew = @'
// 8b.3: CSV import. Registered BEFORE the contacts apiResource so "imports" is never read as a {contact} id.
        Route::post('contacts/imports', [ContactImportController::class, 'store'])->middleware('throttle:10,1');

        Route::get('contacts/imports/{import}', [ContactImportController::class, 'show']);

        Route::apiResource('contacts', ContactController::class);
'@

Patch-Once -Path 'routes\api.php' `
    -Old "Route::apiResource('contacts', ContactController::class);" `
    -New $routeNew `
    -Marker 'contacts/imports'

# ---------------------------------------------------------------- 12. Defense in depth: explicit tenant predicate in duplicate detection
Patch-Once -Path 'app\Domain\Contacts\Matching\DuplicateDetectionService.php' `
    -Old 'use Illuminate\Support\Collection;' `
    -New ('use App\Domain\Tenant\TenantContext;' + "`n" + 'use Illuminate\Support\Collection;') `
    -Marker 'use App\Domain\Tenant\TenantContext;'

$dupNew = @'
->when($excludeContactId, fn ($q) => $q->whereKeyNot($excludeContactId))
                ->when(TenantContext::id(), fn ($q, $tenantId) => $q->where('tenant_id', $tenantId))
'@

Patch-Once -Path 'app\Domain\Contacts\Matching\DuplicateDetectionService.php' `
    -Old '->when($excludeContactId, fn ($q) => $q->whereKeyNot($excludeContactId))' `
    -New $dupNew `
    -Marker 'TenantContext::id()'

Write-Host ''
Write-Host 'Done. Next: php artisan migrate ; php artisan test --filter=ContactImport ; php artisan test'
Write-Host 'NOTE: not shown, so not touched: how phpunit.xml sets QUEUE_CONNECTION (tests fake the queue), the Tenant model namespace (test helper falls back between App\Models\Tenant and App\Domain\Tenant\Tenant), and BelongsToTenant internals (the job sets tenant predicates explicitly because queue workers are console processes).'
