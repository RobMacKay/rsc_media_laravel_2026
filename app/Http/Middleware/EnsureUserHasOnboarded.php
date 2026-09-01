<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send a client who has not been through the welcome wizard to it.
 *
 * Studio staff are exempt: the wizard collects a client business's details,
 * which is not a thing an admin account has.
 */
class EnsureUserHasOnboarded
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_admin && ! $user->hasOnboarded()) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
