<?php

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function docpdfSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function docpdfUser(Tenant $tenant, ?string $role = 'manager'): User
{
    docpdfSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function docpdfQuote(User $actor, string $status = 'accepted'): int
{
    docpdfSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Website build',
        'currency' => 'USD',
        'notes' => 'Thank you & see you soon',
        'lines' => [
            ['name' => 'Design <work>', 'quantity' => 2, 'unit_price' => '10.00', 'tax_category' => 'standard'],
            ['name' => 'Hosting', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'],
        ],
    ])->id;

    DB::table('quotes')->where('id', $id)->update(['status' => $status]);

    return $id;
}

function docpdfInvoice(User $actor): int
{
    $quoteId = docpdfQuote($actor);
    docpdfSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quoteId)->id;
}

it('docpdf renders a quote as an inline PDF or an attachment, for viewers too', function () {
    $b = Tenant::factory()->create();
    $theirs = docpdfQuote(docpdfUser($b));

    $a = Tenant::factory()->create();
    $manager = docpdfUser($a);
    $viewer = docpdfUser($a, 'viewer');
    $noRole = docpdfUser($a, null);
    $mine = docpdfQuote($manager);

    Sanctum::actingAs($viewer);
    $inline = $this->get("/api/v1/quotes/{$mine}/pdf")->assertOk();
    expect($inline->getContent())->toStartWith('%PDF')
        ->and($inline->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($inline->headers->get('Content-Disposition'))->toContain('inline')->toContain('Q-00001.pdf');

    $download = $this->get("/api/v1/quotes/{$mine}/pdf?download=1")->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Q-00001.pdf');

    $this->get("/api/v1/quotes/{$theirs}/pdf")->assertNotFound();
    $this->get('/api/v1/quotes/abc/pdf')->assertNotFound();

    Sanctum::actingAs($noRole);
    $this->get("/api/v1/quotes/{$mine}/pdf")->assertForbidden();
});

it('docpdf renders an invoice as a PDF and keeps tenants apart', function () {
    $b = Tenant::factory()->create();
    $theirs = docpdfInvoice(docpdfUser($b));

    $a = Tenant::factory()->create();
    $manager = docpdfUser($a);
    $noRole = docpdfUser($a, null);
    $mine = docpdfInvoice($manager);

    Sanctum::actingAs($manager);
    $res = $this->get("/api/v1/invoices/{$mine}/pdf?download=1")->assertOk();
    expect($res->getContent())->toStartWith('%PDF')
        ->and($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($res->headers->get('Content-Disposition'))->toContain('attachment')->toContain('INV-00001.pdf');

    $this->get("/api/v1/invoices/{$theirs}/pdf")->assertNotFound();

    Sanctum::actingAs($noRole);
    $this->get("/api/v1/invoices/{$mine}/pdf")->assertForbidden();
});
