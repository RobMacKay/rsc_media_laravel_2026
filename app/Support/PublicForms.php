<?php

namespace App\Support;

/**
 * The unauthenticated forms anyone on the internet can post to.
 *
 * Fortify owns these routes, so the guards are applied by name from one list
 * rather than hung off route definitions we do not write. Everything else —
 * logout, anything behind a login, the signed welcome link — is left alone.
 */
class PublicForms
{
    /**
     * Every public form we guard.
     *
     * @var array<int, string>
     */
    public const ROUTES = [
        'register.store',
        'password.email',
        'login.store',
    ];

    /**
     * The forms that are also refused if they come back too quickly.
     *
     * Only the long ones. Someone logging in with a saved password can be
     * through the form in well under a second, and a returning client being
     * told to slow down would be a real cost for no real gain.
     *
     * @var array<int, string>
     */
    public const TIMED_ROUTES = [
        'register.store',
    ];
}
