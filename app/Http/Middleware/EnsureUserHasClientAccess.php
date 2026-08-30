<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasClientAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$abilities  Any of "billing", "tickets" or "team".
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        abort_if(! $user || ! $user->currentTeam, 403);

        $access = $user->accessFor();

        foreach ($abilities as $ability) {
            abort_unless(match ($ability) {
                'billing' => $access->canSeeBilling(),
                'tickets' => $access->canRaiseTickets(),
                'team' => $access->canManageTeam(),
                default => false,
            }, 403);
        }

        return $next($request);
    }
}
