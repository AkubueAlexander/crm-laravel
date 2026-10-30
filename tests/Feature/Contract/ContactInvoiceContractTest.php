<?php

use App\Http\Resources\ContactResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

// 15.4b: contact + invoice Resource output committed as fixtures; the Vitest contract2 test parses them with the real Zod schemas.
// Regenerate after an intentional Resource change: UPDATE_CONTRACT=1 php artisan test tests/Feature/Contract

function ctriRender(JsonResource $resource): array
{
    $data = $resource->toResponse(Request::create('/'))->getData(true);

    if (count($data) === 1 && isset($data['data']) && is_array($data['data'])) {
        $data = $data['data'];
    }

    return $data;
}

function ctriAssertFixture(string $name, array $payload): void
{
    $path = base_path('frontend/src/test/contract/'.$name.'.json');
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $exists = file_exists($path);

    if (! $exists && getenv('CI')) {
        throw new RuntimeException("Missing contract fixture {$name}.json - generate it locally and commit it.");
    }

    if (! $exists || getenv('UPDATE_CONTRACT')) {
        file_put_contents($path, $json);
    }

    expect(json_decode(file_get_contents($path), true))->toEqual(json_decode($json, true));
}

function ctriTime(string $value): Carbon
{
    return Carbon::parse($value, 'UTC');
}

function ctriContact(array $overrides = []): Contact
{
    return (new Contact())->forceFill(array_merge([
        'id' => 501,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        'phone' => '+234 801 234 5678',
        'job_title' => 'Head of Engineering',
        'account_id' => 77,
        'created_at' => ctriTime('2026-01-15 09:30:00'),
        'updated_at' => ctriTime('2026-02-01 14:05:00'),
    ], $overrides));
}

function ctriInvoice(array $overrides = []): Invoice
{
    return (new Invoice())->forceFill(array_merge([
        'id' => 901,
        'invoice_seq' => 12,
        'invoice_number' => 'INV-0012',
        'status' => 'issued',
        'lock_version' => 3,
        'quote_id' => 44,
        'title' => 'Website retainer',
        'account_id' => 77,
        'contact_id' => 501,
        'currency' => 'USD',
        'payment_terms_days' => 30,
        'issue_date' => ctriTime('2026-03-01 00:00:00'),
        'due_date' => ctriTime('2026-03-31 00:00:00'),
        'notes' => 'Thank you for your business.',
        'terms' => 'Net 30.',
        'subtotal' => '1000.00',
        'tax_total' => '75.00',
        'total' => '1075.00',
        'amount_paid' => '400.00',
        'issued_at' => ctriTime('2026-03-01 10:00:00'),
        'paid_at' => null,
        'voided_at' => null,
        'void_reason' => null,
        'created_by' => 5,
        'created_at' => ctriTime('2026-02-28 08:00:00'),
        'updated_at' => ctriTime('2026-03-05 12:00:00'),
    ], $overrides));
}

function ctriLines(): EloquentCollection
{
    $line = fn (array $f) => (object) $f;

    return new EloquentCollection([
        $line([
            'id' => 1, 'position' => 1, 'product_id' => 10, 'product_variant_id' => 100, 'sku' => 'WEB-001',
            'name' => 'Design sprint', 'description' => 'Two-week sprint', 'quantity' => '2.00', 'unit_price' => '400.00',
            'discount_percent' => '10.00', 'tax_category' => 'standard', 'tax_rate' => '7.50', 'line_total' => '720.00', 'tax_amount' => '54.00',
        ]),
        $line([
            'id' => 2, 'position' => 2, 'product_id' => null, 'product_variant_id' => null, 'sku' => null,
            'name' => 'Custom hosting setup', 'description' => null, 'quantity' => '1.00', 'unit_price' => '280.00',
            'discount_percent' => '0.00', 'tax_category' => 'standard', 'tax_rate' => '7.50', 'line_total' => '280.00', 'tax_amount' => '21.00',
        ]),
    ]);
}

function ctriPayments(): EloquentCollection
{
    $base = [
        'method' => 'bank_transfer', 'recorded_by' => 5, 'voided_at' => null, 'void_reason' => null,
    ];

    return new EloquentCollection([
        (new Payment())->forceFill(array_merge($base, [
            'id' => 1, 'amount' => '400.00', 'paid_on' => ctriTime('2026-03-10 00:00:00'),
            'reference' => 'TRX-889', 'note' => 'First instalment', 'created_at' => ctriTime('2026-03-10 11:00:00'),
        ])),
        (new Payment())->forceFill(array_merge($base, [
            'id' => 2, 'amount' => '150.00', 'method' => 'cash', 'paid_on' => ctriTime('2026-03-12 00:00:00'),
            'reference' => null, 'note' => null, 'recorded_by' => null,
            'voided_at' => ctriTime('2026-03-13 09:00:00'), 'void_reason' => 'Entered twice',
            'created_at' => ctriTime('2026-03-12 15:00:00'),
        ])),
    ]);
}

test('contact resource matches the committed full fixture', function () {
    ctriAssertFixture('contact-full', ctriRender(new ContactResource(ctriContact())));
});

test('contact resource matches the committed nullable fixture', function () {
    $contact = ctriContact([
        'id' => 502,
        'first_name' => null,
        'email' => null,
        'phone' => null,
        'job_title' => null,
        'account_id' => null,
    ]);

    ctriAssertFixture('contact-nullable', ctriRender(new ContactResource($contact)));
});

test('invoice resource matches the committed full fixture (lines and payments loaded)', function () {
    $invoice = ctriInvoice();
    $invoice->setRelation('lines', ctriLines());
    $invoice->setRelation('payments', ctriPayments());

    ctriAssertFixture('invoice-full', ctriRender(new InvoiceResource($invoice)));
});

test('invoice resource matches the committed list-row fixture (lines_count, nulls)', function () {
    $invoice = ctriInvoice([
        'id' => 902,
        'status' => 'draft',
        'quote_id' => null,
        'account_id' => null,
        'contact_id' => null,
        'issue_date' => null,
        'due_date' => null,
        'notes' => null,
        'terms' => null,
        'amount_paid' => '0.00',
        'issued_at' => null,
        'created_by' => null,
        'lines_count' => 3,
    ]);

    ctriAssertFixture('invoice-list', ctriRender(new InvoiceResource($invoice)));
});
