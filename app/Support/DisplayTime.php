<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Timestamps are stored in UTC and shown in the studio's own timezone.
 *
 * UTC in the database is unambiguous and never shifts under us, so the
 * conversion happens at the point of display and has to be asked for:
 *
 *     {{ shown($ticket->created_at)->format('j M, H:i') }}
 *
 * Without it every clock time is an hour out for half the year, and anything
 * that happened between midnight and one in the morning British Summer Time
 * is shown as the day before.
 *
 * Date-only columns — `due_on`, `target_on`, `issued_on` — carry no time and
 * must be left alone. They are already right in any timezone.
 */
class DisplayTime
{
    /**
     * Move a timestamp into the timezone people read it in.
     *
     * Takes and returns null so a nullable column reads the same as any other:
     * `shown($invoice->paid_at)?->format('j F')`.
     */
    public static function of(?CarbonInterface $moment): ?CarbonInterface
    {
        return $moment?->copy()->setTimezone(self::timezone());
    }

    /**
     * Get the timezone everything is shown in.
     *
     * Blank counts as unset. `config()` only falls back when the key is
     * missing entirely, so an empty APP_DISPLAY_TIMEZONE would otherwise
     * reach `setTimezone('')` and throw.
     */
    public static function timezone(): string
    {
        $timezone = config('app.display_timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
