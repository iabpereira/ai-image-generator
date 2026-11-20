<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Reports\SpendReport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('aggregates succeeded generations per utc completion day and user, and reconciles with pixelforge', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    Generation::factory()->for($alice)->succeeded()->count(2)->create(['completed_at' => '2026-11-01 23:59:59']);
    Generation::factory()->for($bob)->succeeded()->create(['completed_at' => '2026-11-02 00:00:01']);
    Generation::factory()->for($bob)->failed()->create(['completed_at' => '2026-11-02 10:00:00']);
    Generation::factory()->for($bob)->processing()->create();

    Http::fake(['*/usage*' => Http::response(['data' => [
        ['date' => '2026-11-01', 'cost_usd' => '0.611020000'],
        ['date' => '2026-11-02', 'cost_usd' => '0.305510001'],
    ]])]);

    $report = app(SpendReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

    expect($report['rows'])->toHaveCount(2)
        ->and($report['rows'][0])->toMatchArray([
            'date' => '2026-11-01', 'user_name' => 'Alice', 'generations' => 2,
            'charged' => '0.763775000', 'provider_cost' => '0.611020000', 'profit' => '0.152755000',
        ])
        ->and($report['rows'][1])->toMatchArray(['date' => '2026-11-02', 'user_name' => 'Bob', 'generations' => 1])
        ->and($report['days'][0])->toMatchArray(['pixelforge_cost' => '0.611020000', 'matches' => true])
        ->and($report['days'][1]['matches'])->toBeFalse()
        ->and($report['totals'])->toBe([
            'generations' => 3, 'charged' => '1.145662500', 'provider_cost' => '0.916530000', 'profit' => '0.229132500',
            'effective_markup_basis_points' => 2500,
        ])
        ->and($report['days'][0]['effective_markup_basis_points'])->toBe(2500)
        ->and($report['usage_error'])->toBeNull();
});

it('still reports our numbers when the usage api is unavailable', function () {
    Generation::factory()->succeeded()->create(['completed_at' => '2026-11-01 12:00:00']);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    $report = app(SpendReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));

    expect($report['rows'])->toHaveCount(1)
        ->and($report['days'][0]['pixelforge_cost'])->toBeNull()
        ->and($report['days'][0]['matches'])->toBeNull()
        ->and($report['usage_error'])->toContain('overloaded');
});

it('reports the effective markup as the cost-weighted mix of quoted markups', function () {
    Http::fake(['*/usage*' => Http::response(['data' => []])]);
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create([
        'completed_at' => '2026-11-01 10:00:00', 'markup_basis_points' => 2500,
        'provider_cost' => Money::fromNanos(1_000_000_000), 'charged_amount' => Money::fromNanos(1_250_000_000),
    ]);
    Generation::factory()->for($user)->succeeded()->create([
        'completed_at' => '2026-11-01 11:00:00', 'markup_basis_points' => 3000,
        'provider_cost' => Money::fromNanos(3_000_000_000), 'charged_amount' => Money::fromNanos(3_900_000_000),
    ]);

    $report = app(SpendReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

    expect($report['totals']['effective_markup_basis_points'])->toBe(2875)
        ->and($report['days'][1]['effective_markup_basis_points'])->toBeNull();
});
