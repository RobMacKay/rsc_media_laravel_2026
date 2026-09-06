<?php

namespace App\Http\Middleware;

use App\Rules\TurnstileToken;
use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstile
{
    /**
     * The public forms a bot is worth stopping on.
     *
     * Fortify owns these routes, so the check is applied by name here rather
     * than by hanging middleware off route definitions we do not write.
     * Everything else — logout, anything behind a login, the signed welcome
     * link — is deliberately left alone.
     *
     * @var array<int, string>
     */
    public const PROTECTED_ROUTES = [
        'register.store',
        'password.email',
        'login.store',
    ];

    public function __construct(private Turnstile $turnstile) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldCheck($request)) {
            return $next($request);
        }

        if ($this->turnstile->verify($request->input(Turnstile::FIELD), $request->ip())) {
            return $next($request);
        }

        // Thrown rather than returned so it lands in the session errors bag
        // the auth pages already render, alongside any other field error.
        Validator::make([], [])->after(function ($validator): void {
            $validator->errors()->add(Turnstile::FIELD, __(TurnstileToken::MESSAGE));
        })->validate();

        return $next($request);
    }

    /**
     * Determine whether this request is one of the forms we guard.
     */
    private function shouldCheck(Request $request): bool
    {
        return $this->turnstile->enabled()
            && $request->isMethod('POST')
            && $request->routeIs(...self::PROTECTED_ROUTES);
    }
}
