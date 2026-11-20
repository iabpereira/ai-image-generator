<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Every account, with what an operator needs to pick one to impersonate.
     */
    public function index(): Response
    {
        Gate::authorize('manageUsers');

        return Inertia::render('admin/Users', [
            'users' => User::query()
                ->withCount('generations')
                ->orderBy('name')
                ->paginate(25)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'email_verified' => $user->email_verified_at !== null,
                    'credit_balance' => $user->credit_balance->toDecimalString(),
                    'generations_count' => $user->generations_count,
                    'created_at' => $user->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
