<?php

use App\Domain\Hr\Hris\HrisAdapter;
use App\Domain\Hr\Hris\HrisManager;
use App\Domain\Hr\Hris\NullHrisAdapter;

it('resolves the null adapter when no driver is configured', function () {
    config(['services.hris.driver' => null]);

    $adapter = app(HrisManager::class)->adapter();

    expect($adapter)->toBeInstanceOf(HrisAdapter::class)
        ->and($adapter)->toBeInstanceOf(NullHrisAdapter::class)
        ->and($adapter->name())->toBe('null')
        ->and($adapter->fetchEmployees())->toBe([]);
});

it('resolves the null adapter when the driver is set to null', function () {
    config(['services.hris.driver' => 'null']);

    expect(app(HrisManager::class)->adapter())->toBeInstanceOf(NullHrisAdapter::class);
});

it('refuses an unknown driver', function () {
    config(['services.hris.driver' => 'gusto']);

    app(HrisManager::class)->adapter();
})->throws(InvalidArgumentException::class, 'Unknown HRIS driver [gusto].');
