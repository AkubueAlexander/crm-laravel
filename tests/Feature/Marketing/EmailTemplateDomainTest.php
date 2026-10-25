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