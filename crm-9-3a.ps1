# crm-9-3a.ps1 - 9.3a email templates: table, model, variable whitelist, safe Markdown renderer. Run from the repo root.
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path
if (-not (Test-Path (Join-Path $root 'app\Models\Segment.php'))) {
    throw "Run this from the repo root (app\Models\Segment.php not found)."
}

function Write-NoBom {
    param([string]$RelPath, [string]$Content)
    $full = Join-Path $root $RelPath
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $text = $Content.Replace("`r`n", "`n")
    [System.IO.File]::WriteAllText($full, $text, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "wrote $RelPath"
}

# Find the repo's BelongsToTenant trait so the model imports the right namespace.
$hit = Get-ChildItem (Join-Path $root 'app') -Recurse -Filter *.php |
    Select-String -Pattern '^\s*trait\s+BelongsToTenant\b' | Select-Object -First 1
if (-not $hit) { throw 'Could not find a trait named BelongsToTenant under app\.' }
$nsMatch = Select-String -Path $hit.Path -Pattern '^\s*namespace\s+([^;]+);' | Select-Object -First 1
if (-not $nsMatch) { throw "No namespace found in $($hit.Path)" }
$traitFqcn = $nsMatch.Matches[0].Groups[1].Value.Trim() + '\BelongsToTenant'
Write-Host "using trait $traitFqcn"

# ---------------------------------------------------------------- 1. migration
Write-NoBom 'database/migrations/2026_10_26_100000_create_email_templates_table.php' @'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('subject', 200);
            // Markdown source. Raw HTML in it is stripped at render time (TemplateRenderer).
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'updated_at']);
        });

        // Template names are unique per tenant, case-insensitively (same rule as segments).
        DB::statement('create unique index email_templates_tenant_lower_name_unique on email_templates (tenant_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
'@

# ---------------------------------------------------------------- 2. model
$model = @'
<?php

declare(strict_types=1);

namespace App\Models;

use __TRAIT_FQCN__;
use Illuminate\Database\Eloquent\Model;

/**
 * Markdown email template (9.3). Tenant scoped through BelongsToTenant.
 * tenant_id and created_by are only ever set by the Actions (never mass assigned).
 */
class EmailTemplate extends Model
{
    use BelongsToTenant;

    public const MAX_NAME = 160;
    public const MAX_SUBJECT = 200;
    public const MAX_BODY = 50000;

    protected $fillable = ['name', 'subject', 'body'];
}
'@
Write-NoBom 'app/Models/EmailTemplate.php' ($model.Replace('__TRAIT_FQCN__', $traitFqcn))

# ---------------------------------------------------------------- 3. TemplateVariables
Write-NoBom 'app/Domain/Marketing/Templates/TemplateVariables.php' @'
<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Templates;

use App\Models\EmailTemplate;
use Illuminate\Validation\ValidationException;

/**
 * The single whitelist of {{ variables }} a template may use, plus the save-time validation
 * (2.4: Actions call assertValid again; the request/UI layers are only UX).
 */
final class TemplateVariables
{
    public const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /** name => human description (the UI catalog renders from this). */
    public const VARIABLES = [
        'first_name' => 'First word of the contact name',
        'name' => 'Full contact name',
        'email' => 'Contact email address',
        'job_title' => 'Contact job title',
    ];

    /** Values used by the preview, never real contact data. */
    public const SAMPLE = [
        'first_name' => 'Ada',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'job_title' => 'Head of Engineering',
    ];

    /** @return list<array{name: string, label: string, sample: string}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::VARIABLES as $name => $label) {
            $out[] = ['name' => $name, 'label' => $label, 'sample' => self::SAMPLE[$name]];
        }

        return $out;
    }

    /** @return list<string> distinct variable names used in the text, in order of first use */
    public static function usedIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    public static function unknownIn(string $text): array
    {
        return array_values(array_diff(self::usedIn($text), array_keys(self::VARIABLES)));
    }

    /**
     * Build the value map for one contact (9.4 calls this per recipient).
     *
     * @return array<string, string>
     */
    public static function valuesFor(?string $name, ?string $email, ?string $jobTitle): array
    {
        $name = trim((string) $name);
        $first = $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? '');

        return [
            'first_name' => $first,
            'name' => $name,
            'email' => trim((string) $email),
            'job_title' => trim((string) $jobTitle),
        ];
    }

    /** @return array<string, list<string>> errors keyed by subject|body, empty when valid */
    public static function errors(string $subject, string $body): array
    {
        $errors = [];

        $trimmedSubject = trim($subject);
        if ($trimmedSubject === '') {
            $errors['subject'][] = 'Subject is required.';
        } elseif (mb_strlen($trimmedSubject) > EmailTemplate::MAX_SUBJECT) {
            $errors['subject'][] = 'Subject must be at most '.EmailTemplate::MAX_SUBJECT.' characters.';
        }
        if (preg_match('/[\r\n]/', $subject) === 1) {
            $errors['subject'][] = 'Subject must be a single line.';
        }

        if (trim($body) === '') {
            $errors['body'][] = 'Body is required.';
        } elseif (mb_strlen($body) > EmailTemplate::MAX_BODY) {
            $errors['body'][] = 'Body must be at most '.EmailTemplate::MAX_BODY.' characters.';
        }

        foreach (['subject' => $subject, 'body' => $body] as $key => $text) {
            $unknown = self::unknownIn($text);
            if ($unknown !== []) {
                $errors[$key][] = 'Unknown variable(s): '
                    .implode(', ', array_map(static fn (string $n): string => '{{ '.$n.' }}', $unknown)).'.';
            }
        }

        return $errors;
    }

    public static function assertValid(string $subject, string $body): void
    {
        $errors = self::errors($subject, $body);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
'@

# ---------------------------------------------------------------- 4. TemplateRenderer
Write-NoBom 'app/Domain/Marketing/Templates/TemplateRenderer.php' @'
<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Templates;

use Illuminate\Support\Str;

/**
 * Renders a template's Markdown + subject for one recipient. The editor preview (9.3) and the
 * send job (9.4) both call this, so what the author previews is what recipients get.
 *
 * Safety: raw HTML in the Markdown is stripped and unsafe link schemes are dropped. Variables are
 * substituted AFTER Markdown rendering (so a contact name cannot inject Markdown) and HTML-escaped
 * in the body. Subject values lose line breaks (no header injection). Unknown variables render empty.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, string>  $values  see TemplateVariables::valuesFor()
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $subject, string $body, array $values): array
    {
        $fragment = Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = self::substitute($fragment, $values, static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $subjectOut = self::substitute($subject, $values, static fn (string $v): string => (string) preg_replace('/[\r\n]+/', ' ', $v));

        return [
            'subject' => trim($subjectOut),
            'html' => self::wrap($html),
            'text' => self::toText($html),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  callable(string): string  $escape
     */
    private static function substitute(string $text, array $values, callable $escape): string
    {
        return (string) preg_replace_callback(
            TemplateVariables::PATTERN,
            static function (array $m) use ($values, $escape): string {
                $name = $m[1];
                if (! array_key_exists($name, TemplateVariables::VARIABLES)) {
                    return '';
                }

                return $escape((string) ($values[$name] ?? ''));
            },
            $text,
        );
    }

    private static function wrap(string $fragment): string
    {
        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body style="margin:0;padding:24px;background:#f5f5f4;">'
            .'<div style="max-width:600px;margin:0 auto;padding:24px;background:#ffffff;'
            .'font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1c1917;">'
            .$fragment
            .'</div></body></html>';
    }

    private static function toText(string $html): string
    {
        // Keep link targets readable in the plain-text alternative: "Docs (https://...)".
        $text = (string) preg_replace_callback(
            '#<a\s+[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[2]));

                return ($label === '' || $label === $url) ? $url : $label.' ('.$url.')';
            },
            $html,
        );

        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|h[1-6]|blockquote|pre|ul|ol)>#i', "\n\n", $text);
        $text = (string) preg_replace('#</li>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
'@

# ---------------------------------------------------------------- 5. tests
Write-NoBom 'tests/Feature/Marketing/EmailTemplateDomainTest.php' @'
<?php

declare(strict_types=1);

use App\Domain\Marketing\Templates\TemplateRenderer;
use App\Domain\Marketing\Templates\TemplateVariables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('creates the email_templates table with the expected columns', function () {
    expect(Schema::hasColumns('email_templates', [
        'id', 'tenant_id', 'name', 'subject', 'body', 'created_by', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('substitutes known variables, escaping HTML in the body but not in the subject', function () {
    $values = TemplateVariables::valuesFor('<b>Ada</b> & Co', 'ada@example.com', 'CTO');

    $out = TemplateRenderer::render('Hi {{ first_name }}', 'Hello {{name}}, mail {{ email }}', $values);

    expect($out['html'])->toContain('Hello &lt;b&gt;Ada&lt;/b&gt; &amp; Co, mail ada@example.com')
        ->and($out['subject'])->toBe('Hi <b>Ada</b>')
        ->and($out['text'])->toContain('Hello <b>Ada</b> & Co');
});

it('never lets a value add line breaks to the subject', function () {
    $values = TemplateVariables::valuesFor("Ada\r\nBcc: evil@example.com", null, null);

    $out = TemplateRenderer::render('Hi {{ name }}', 'Body', $values);

    expect($out['subject'])->not->toContain("\n")->not->toContain("\r");
});

it('does not let a contact name inject Markdown or HTML', function () {
    $values = TemplateVariables::valuesFor('[click](https://evil.example) <img src=x>', null, null);

    $out = TemplateRenderer::render('S', 'Hi {{ name }}', $values);

    expect($out['html'])->not->toContain('<a href="https://evil.example"')
        ->and($out['html'])->not->toContain('<img');
});

it('strips raw HTML and unsafe link schemes from the template source', function () {
    $out = TemplateRenderer::render('S', "<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n<iframe src=\"https://evil.example\"></iframe>", []);

    expect($out['html'])->not->toContain('<script')
        ->and($out['html'])->not->toContain('<iframe')
        ->and($out['html'])->not->toContain('javascript:');
});

it('renders Markdown and keeps link targets in the plain-text version', function () {
    $out = TemplateRenderer::render('S', "# Welcome\n\nRead the [docs](https://example.com/a).", []);

    expect($out['html'])->toContain('<h1>Welcome</h1>')
        ->and($out['html'])->toContain('<a href="https://example.com/a">docs</a>')
        ->and($out['text'])->toContain('Read the docs (https://example.com/a).');
});

it('renders unknown variables as empty instead of leaking the placeholder', function () {
    $out = TemplateRenderer::render('Hi {{ secret }}', 'Body {{ password }}.', []);

    expect($out['subject'])->toBe('Hi')
        ->and($out['html'])->not->toContain('{{');
});

it('derives first_name from the first word of the name', function () {
    expect(TemplateVariables::valuesFor('  Segun   Adebayo ', null, null)['first_name'])->toBe('Segun')
        ->and(TemplateVariables::valuesFor(null, null, null)['first_name'])->toBe('');
});

it('finds used and unknown variables with flexible spacing', function () {
    $text = '{{name}} {{  first_name }} {{ Name }} {{ nope }} {{ name }}';

    expect(TemplateVariables::usedIn($text))->toBe(['name', 'first_name', 'Name', 'nope'])
        ->and(TemplateVariables::unknownIn($text))->toBe(['Name', 'nope']);
});

it('reports validation errors per field and accepts a valid template', function () {
    $errors = TemplateVariables::errors('', "   ");
    expect($errors)->toHaveKeys(['subject', 'body']);

    $errors = TemplateVariables::errors("Two\nlines {{ ghost }}", 'ok {{ other }}');
    expect($errors['subject'])->toHaveCount(2)
        ->and($errors['body'])->toHaveCount(1);

    expect(TemplateVariables::errors('Hi {{ first_name }}', 'Hello {{ name }}'))->toBe([]);
    TemplateVariables::assertValid('Hi {{ first_name }}', 'Hello {{ name }}');
});

it('throws a ValidationException from assertValid for an invalid template', function () {
    TemplateVariables::assertValid('Hi', 'Body {{ ghost }}');
})->throws(ValidationException::class);

it('exposes a catalog with a sample for every variable', function () {
    $catalog = TemplateVariables::catalog();

    expect(array_column($catalog, 'name'))->toBe(array_keys(TemplateVariables::VARIABLES));
    foreach ($catalog as $entry) {
        expect($entry['sample'])->not->toBe('');
    }
});
'@

Write-Host ''
Write-Host 'Done: 5 files written. Next: php artisan migrate ; php artisan test'
Write-Host "Referenced but NOT shown to me (untouched): the BelongsToTenant trait (auto-detected as $traitFqcn), tests/Pest.php (assumes Feature tests already boot the app and database). Routes/API for templates come in 9.3b."
