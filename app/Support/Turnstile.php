<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a Turnstile token is checked with Cloudflare.
 *
 * A token from the browser proves nothing on its own — anyone can post a made
 * up string — so every submission is verified server side before it counts.
 */
class Turnstile
{
    /**
     * Where tokens are verified.
     */
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * How long to wait on Cloudflare before giving up, in seconds.
     */
    public const TIMEOUT = 5;

    /**
     * The field the widget posts its token in.
     */
    public const FIELD = 'cf-turnstile-response';

    /**
     * Determine whether bot protection is switched on.
     *
     * Both keys have to be present: a site key with no secret would render a
     * widget nothing ever checks, which is worse than not having one.
     */
    public function enabled(): bool
    {
        return $this->siteKey() !== null && $this->secretKey() !== null;
    }

    /**
     * Get the public key the widget renders with.
     */
    public function siteKey(): ?string
    {
        return $this->key('site_key');
    }

    /**
     * Get the private key tokens are verified with.
     */
    public function secretKey(): ?string
    {
        return $this->key('secret_key');
    }

    /**
     * Determine whether this token came from a real visitor.
     *
     * A missing token is refused outright. A token Cloudflare rejects is
     * refused. But if Cloudflare cannot be reached at all we let the
     * submission through: an outage at their end should not take the enquiry
     * form down with it, and losing real work is worse than the spam.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $this->secretKey(),
                    'response' => $token,
                    'remoteip' => $ip,
                    // Cloudflare rejects a token it has already seen. The key
                    // makes a retry of the same submission idempotent rather
                    // than a duplicate.
                    'idempotency_key' => (string) Str::uuid(),
                ]));
        } catch (Throwable $e) {
            Log::warning('Could not reach Turnstile, letting the submission through.', [
                'exception' => $e->getMessage(),
            ]);

            return true;
        }

        if ($response->failed()) {
            Log::warning('Turnstile answered with an error, letting the submission through.', [
                'status' => $response->status(),
            ]);

            return true;
        }

        $passed = $response->json('success') === true;

        if (! $passed) {
            Log::info('Turnstile refused a submission.', [
                'errors' => $response->json('error-codes'),
            ]);
        }

        return $passed;
    }

    /**
     * Get the options the widget is rendered with.
     *
     * `interaction-only` is what keeps the site clear of boxes: Cloudflare
     * draws nothing at all unless this particular visitor has to click
     * something, which almost nobody does. It is deliberately not the
     * "invisible" widget type — that one has no way to ask a visitor it is
     * unsure about, so a false positive is simply turned away.
     *
     * @return array<string, string>
     */
    public function widgetOptions(string $action): array
    {
        return [
            'sitekey' => (string) $this->siteKey(),
            'action' => $action,
            'theme' => 'auto',
            'appearance' => 'interaction-only',
            'response-field-name' => self::FIELD,
        ];
    }

    /**
     * Read one of the configured keys, treating blank as absent.
     */
    private function key(string $name): ?string
    {
        $value = config("services.turnstile.{$name}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
