<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Http::fake(['*/usage*' => Http::response(['data' => []])]));

it('lets only admins view the report', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('viewAdminReport'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewAdminReport'))->toBeFalse();

    $this->get(route('admin.spend-report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.spend-report'))->assertForbidden();
});

it('defaults to the last 7 utc days', function () {
    $this->travelTo('2026-11-20 12:00:00');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.spend-report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/SpendReport')
            ->where('filters.start_date', '2026-11-14')
            ->where('filters.end_date', '2026-11-20')
            ->has('report.days', 7)
            ->where('currentMarkupBasisPoints', 2500));
});

it('rejects ranges longer than pixelforge allows', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.spend-report', ['start_date' => '2026-01-01', 'end_date' => '2026-11-01']))
        ->assertSessionHasErrors('end_date');
});

it('promotes a user by email', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:promote', ['email' => 'ops@example.com'])->assertSuccessful();
    $this->artisan('users:promote', ['email' => 'nobody@example.com'])->assertFailed();

    expect($user->refresh()->is_admin)->toBeTrue();
});
