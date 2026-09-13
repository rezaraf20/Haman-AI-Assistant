<?php
namespace App\Listeners;

use App\Models\User;
use App\Support\PlatformActivity;
use Illuminate\Auth\Events\{Failed, Login, Logout};

/**
 * Records platform staff signing in and out, and failed attempts on their
 * accounts.
 *
 * Hung off Laravel's auth events rather than any one login screen, so it
 * covers every path into the panel — Filament's own Login page and the
 * Livewire email login both end up in the guard, and a future third way
 * would too.
 *
 * Only platform staff are recorded. Customers log in through the same guard
 * many times a day; their sign-ins are not what this table is for, and
 * mixing them in would bury the handful of rows that matter.
 *
 * A failed attempt is looked up by email, because at that point there is no
 * authenticated user to ask. An attempt against an address that is not staff
 * is ignored — otherwise anyone could fill this table by POSTing invented
 * addresses at the login form.
 */
class RecordPlatformAuthActivity
{
    // Named on* rather than handle*: Laravel discovers any public handle*
    // method in app/Listeners and registers it automatically, which would
    // stack a second subscription on top of the explicit one in
    // AppServiceProvider and record every sign-in twice.

    public function onLogin(Login $event): void
    {
        $user = $event->user;
        if (!$user instanceof User || $user->platform_role === null) return;

        PlatformActivity::record('login', subjectType: 'user', subjectId: (string) $user->id, actor: $user);
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;
        if (!$user instanceof User || $user->platform_role === null) return;

        PlatformActivity::record('logout', subjectType: 'user', subjectId: (string) $user->id, actor: $user);
    }

    public function onFailed(Failed $event): void
    {
        // $event->user is the resolved account when the address exists and
        // the password was wrong; null when the address is unknown.
        $user = $event->user;

        if (!$user instanceof User) {
            $email = $event->credentials['email'] ?? null;
            if (!$email) return;
            $user = User::where('email', $email)->whereNotNull('platform_role')->first();
        }

        if (!$user instanceof User || $user->platform_role === null) return;

        PlatformActivity::record(
            'login_failed',
            subjectType: 'user',
            subjectId: (string) $user->id,
            actor: $user,
        );
    }
}
