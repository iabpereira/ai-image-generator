<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Lets an admin act as another user for testing, remembering who they really are
 * in the session so they can switch back. The session id is regenerated on every
 * switch to prevent fixation.
 */
final class Impersonation
{
    private const string SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $user): void
    {
        Session::put(self::SESSION_KEY, $admin->id);
        Auth::guard('web')->login($user);
        Session::regenerate();
    }

    /**
     * Switch back to the admin. Returns false (and signs out completely) when the
     * original account can no longer be restored as an admin.
     */
    public function stop(): bool
    {
        $admin = $this->impersonator();
        Session::forget(self::SESSION_KEY);

        if ($admin === null || ! $admin->is_admin) {
            Auth::guard('web')->logout();
            Session::invalidate();
            Session::regenerateToken();

            return false;
        }

        Auth::guard('web')->login($admin);
        Session::regenerate();

        return true;
    }

    public function isActive(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public function impersonator(): ?User
    {
        $id = Session::get(self::SESSION_KEY);

        return is_int($id) ? User::query()->find($id) : null;
    }
}
