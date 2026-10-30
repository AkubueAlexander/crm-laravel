<?php

use App\Domain\Deals\Actions\TransitionDealAction;
use App\Http\Resources\DealResource;
use App\Http\Resources\QuoteResource;
use App\Models\Deal;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/*
 * 15.4: API contract. The real Resource output is written to frontend/src/test/contract/*.json (committed).
 * This test fails when a Resource changes without the fixture being regenerated (UPDATE_CONTRACT=1);
 * the Vitest contract test then parses the same fixtures with the real Zod schemas.
 * Models are built in memory (no DB writes): a Resource is a pure shape function.
 */

function ctrRender(JsonResource $resource): array
{
    $json = json_decode($resource->toResponse(Request::create('/'))->getContent(), true);

    return (is_array($json) && count($json) === 1 && array_key_exists('data', $json)) ? $json['data'] : $json;
}

function ctrAssertFixture(string $name, array $actual): void
{
    $path = base_path('frontend/src/test/contract/' . $name . '.json');
    $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    $missing = ! file_exists($path);
    if ($missing && getenv('CI')) {
        throw new RuntimeException("Contract fixture missing: {$name}.json. Run the backend tests locally and commit it.");
    }

    if ($missing || getenv('UPDATE_CONTRACT') === '1') {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $json);
    }

    $expected = json_decode(file_get_contents($path), true);
    expect($expected)->toEqual(json_decode($json, true));
}

function ctrDeal(array $over = []): Deal
{
    $deal = new Deal();
    $deal->forceFill(array_merge([
        'id' => 11,
        'board_id' => 1,
        'name' => 'Contract deal',
        'owner_id' => 5,
        'amount' => '1500.00',
        'expected_close_date' => '2026-03-31',
        'state' => TransitionDealAction::STATE_MAP['qualified'],
        'source' => 'web_form',
        'lead_form_id' => 3,
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'spring',
        'lock_version' => 2,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ], $over));

    return $deal;
}

function ctrQuote(array $over = []): Quote
{
    $quote = new Quote();
    $quote->forceFill(array_merge([
        'id' => 21,
        'quote_seq' => 7,
        'quote_number' => 'Q-0007',
        'version' => 2,
        'status' => 'pending_approval',
        'lock_version' => 3,
        'title' => 'Contract quote',
        'account_id' => 4,
        'contact_id' => 9,
        'currency' => 'USD',
        'valid_until' => '2026-03-31',
        'notes' => 'Some notes',
        'terms' => 'Net 30',
        'subtotal' => '100.00',
        'tax_total' => '10.00',
        'total' => '110.00',
        'created_by' => 5,
        'submitted_at' => '2026-01-02 10:00:00',
        'decided_at' => '2026-01-03 10:00:00',
        'decision_note' => 'Looks fine',
        'sent_at' => '2026-01-04 10:00:00',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ], $over));

    return $quote;
}

function ctrQuoteLines(): Collection
{
    return new Collection([
        (object) [
            'id' => 1, 'position' => 1, 'product_id' => 8, 'product_variant_id' => 2, 'sku' => 'SKU-1',
            'name' => 'Catalog line', 'description' => 'Desc', 'quantity' => '2.0000', 'unit_price' => '30.00',
            'discount_percent' => '0.00', 'tax_category' => 'standard', 'tax_rate' => '10.00',
            'line_total' => '60.00', 'tax_amount' => '6.00',
        ],
        (object) [
            'id' => 2, 'position' => 2, 'product_id' => null, 'product_variant_id' => null, 'sku' => null,
            'name' => 'Custom line', 'description' => null, 'quantity' => '1.0000', 'unit_price' => '40.00',
            'discount_percent' => '0.00', 'tax_category' => 'standard', 'tax_rate' => '10.00',
            'line_total' => '40.00', 'tax_amount' => '4.00',
        ],
    ]);
}

it('deal resource matches its contract fixture (full)', function () {
    ctrAssertFixture('deal-full', ctrRender(new DealResource(ctrDeal())));
});

it('deal resource matches its contract fixture (nullable fields empty)', function () {
    $deal = ctrDeal([
        'owner_id' => null, 'amount' => null, 'expected_close_date' => null, 'source' => null,
        'lead_form_id' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null,
    ]);

    ctrAssertFixture('deal-nullable', ctrRender(new DealResource($deal)));
});

it('quote resource matches its contract fixture (show: lines loaded)', function () {
    $quote = ctrQuote();
    $quote->setRelation('lines', ctrQuoteLines());

    ctrAssertFixture('quote-full', ctrRender(new QuoteResource($quote)));
});

it('quote resource matches its contract fixture (list row: lines_count)', function () {
    $quote = ctrQuote([
        'status' => 'draft', 'account_id' => null, 'contact_id' => null, 'valid_until' => null, 'notes' => null,
        'terms' => null, 'created_by' => null, 'submitted_at' => null, 'decided_at' => null, 'decision_note' => null,
        'sent_at' => null, 'lines_count' => 2,
    ]);

    ctrAssertFixture('quote-list', ctrRender(new QuoteResource($quote)));
});
