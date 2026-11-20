<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ImpersonationController extends Controller
{
    /**
     * Start acting as the given user.
     */
    public function store(Request $request, User $user, Impersonation $impersonation): RedirectResponse
    {
        Gate::authorize('impersonate', $user);

        $impersonation->start($request->user(), $user);

        return to_route('dashboard');
    }

    /**
     * Switch back to the admin account.
     */
    public function destroy(Impersonation $impersonation): RedirectResponse
    {
        abort_unless($impersonation->isActive(), 403);

        if (! $impersonation->stop()) {
            return to_route('login');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Back to your account.')]);

        return to_route('admin.users.index');
    }
}
