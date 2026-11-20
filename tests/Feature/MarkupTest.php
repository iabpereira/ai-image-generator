<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\MarkupChange;
use App\Models\User;
use App\Services\Generations\GenerationLifecycle;
use App\Services\Pricing\Markup;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('uses the configured default until an admin changes it', function () {
    expect(app(Markup::class)->currentBasisPoints())->toBe(2500);

    app(Markup::class)->change(User::factory()->admin()->create(), 1250);

    expect(app(Markup::class)->currentBasisPoints())->toBe(1250);
});

it('lets an admin change the markup and records who and when', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.markup.store'), ['markup_percent' => '12.5'])
        ->assertRedirect(route('admin.markup.index'));

    expect(MarkupChange::sole())
        ->markup_basis_points->toBe(1250)
        ->previous_basis_points->toBe(2500)
        ->changed_by->toBe($admin->id);

    $this->get(route('admin.markup.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/Markup')
            ->where('markupBasisPoints', 1250)
            ->where('history.data.0.changed_by.name', $admin->name)
            ->where('history.data.0.previous_basis_points', 2500));
});

it('keeps markup management to admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.markup.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.markup.store'), ['markup_percent' => '50'])->assertForbidden();

    expect(MarkupChange::count())->toBe(0);
});

it('validates the markup', function (string $value) {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.markup.store'), ['markup_percent' => $value])
        ->assertSessionHasErrors('markup_percent');
})->with(['-1', '500.01', '12.345', 'abc', '']);

it('charges in-flight generations with the markup they were quoted with', function () {
    Queue::fake([StoreGenerationImage::class]);
    $generation = Generation::factory()->processing()->create(['markup_basis_points' => 2500]);

    app(Markup::class)->change(User::factory()->admin()->create(), 5000);
    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded'));

    expect($generation->refresh()->charged_amount->toDecimalString())->toBe('0.381887500');
});
