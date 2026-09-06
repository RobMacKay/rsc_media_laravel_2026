<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The cheap half of bot protection: a field no person can see, and a clock.
 *
 * Costs nothing, needs no third party, and turns away the crude scripts that
 * post to every form they find without ever rendering the page. Turnstile
 * handles anything that gets past it.
 */
class Honeypot
{
    /**
     * The field a person never fills in, because they never see it.
     *
     * Named like something a bot would want to fill in.
     */
    public const FIELD = 'website_url';

    /**
     * The field carrying when the form was handed out.
     */
    public const STAMP = 'form_opened_at';

    /**
     * How long a person takes, at the very least, to fill a form in.
     */
    public const MIN_SECONDS = 2;

    /**
     * Get an encrypted "handed out at" stamp for a form.
     *
     * Encrypted so the clock cannot simply be wound back by editing the page.
     */
    public function stamp(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Determine whether something filled in the invisible field.
     */
    public function trapWasTouched(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Determine whether the form came back faster than a person could type.
     *
     * A stamp that will not decrypt is treated as suspect: it was either
     * tampered with or never rendered by us in the first place.
     */
    public function tooFast(mixed $stamp): bool
    {
        if (! is_string($stamp) || $stamp === '') {
            return true;
        }

        try {
            $openedAt = (int) Crypt::decryptString($stamp);
        } catch (DecryptException) {
            return true;
        }

        return now()->getTimestamp() - $openedAt < self::MIN_SECONDS;
    }
}
