<?php

use Illuminate\Support\Str;

it('lformpg returns 404 for a malformed uuid', function () {
    $this->get('/f/not-a-uuid')->assertNotFound();
});

it('lformpg serves the page without blocking framing', function () {
    $res = $this->get('/f/'.Str::uuid()->toString());

    // 200 when the SPA has been built, 503 (build hint) when not: both must stay frameable.
    expect($res->status())->toBeIn([200, 503]);
    $res->assertHeaderMissing('X-Frame-Options');
});
