<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lets an admin impersonate a regular user and switch back', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ops']);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.impersonation.store', $user))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $user->id)->where('auth.impersonator.name', 'Ops'));

    $this->delete(route('impersonation.destroy'))->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($admin);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.impersonator', null));
});

it('refuses impersonation by regular users, of admins and of oneself', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('admin.impersonation.store', User::factory()->create()))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.impersonation.store', $otherAdmin))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.impersonation.store', $admin))->assertForbidden();

    $this->assertAuthenticatedAs($admin);
});

it('cannot switch back without an impersonation in progress', function () {
    $this->actingAs(User::factory()->create())->delete(route('impersonation.destroy'))->assertForbidden();
});

it('signs out instead of restoring an account that is no longer an admin', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)->post(route('admin.impersonation.store', $user));
    $admin->forceFill(['is_admin' => false])->save();

    $this->delete(route('impersonation.destroy'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('lists users for admins only', function () {
    User::factory()->count(2)->create();

    $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/Users')->has('users.data', 4));
});
