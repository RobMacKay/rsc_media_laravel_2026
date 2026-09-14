<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Deciding whether two spellings are the same client business.
 *
 * Shared by every importer, so they cannot drift on what counts as a match and
 * open a second record for a client already on the books.
 */
class BusinessName
{
    /**
     * Determine whether two names are the same business.
     *
     * Loose enough to match "Mearns and Gill" to "Mearns & Gill", which is the
     * difference between an export and what somebody typed into the studio's
     * own records months earlier.
     */
    public static function matches(string $one, string $other): bool
    {
        return self::normalise($one) === self::normalise($other);
    }

    /**
     * Reduce a name to the form two spellings are compared in.
     */
    public static function normalise(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->replace('&', 'and')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
