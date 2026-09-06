<?php

namespace App\Http\Middleware;

use App\Support\Honeypot;
use App\Support\PublicForms;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class VerifyHoneypot
{
    /**
     * What someone who trips it is told.
     *
     * Vague on purpose: a bot author learns nothing, and the one thing that
     * fixes it for a person who has somehow tripped it is trying again.
     */
    public const MESSAGE = 'That did not go through. Please try again.';

    public function __construct(private Honeypot $honeypot) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs(...PublicForms::ROUTES)) {
            return $next($request);
        }

        if ($this->honeypot->trapWasTouched($request->input(Honeypot::FIELD))) {
            $this->refuse($request, 'the hidden field was filled in');
        }

        if ($request->routeIs(...PublicForms::TIMED_ROUTES)
            && $this->honeypot->tooFast($request->input(Honeypot::STAMP))) {
            $this->refuse($request, 'the form came back too quickly');
        }

        return $next($request);
    }

    /**
     * Turn the submission away.
     */
    private function refuse(Request $request, string $because): never
    {
        Log::info('Turned away a submission: '.$because, [
            'route' => $request->route()?->getName(),
        ]);

        throw ValidationException::withMessages([
            Honeypot::FIELD => __(self::MESSAGE),
        ]);
    }
}
