<?php

use App\Support\DisplayTime;
use Carbon\CarbonInterface;

if (! function_exists('shown')) {
    /**
     * Move a timestamp into the timezone it should be read in.
     *
     * Storage is UTC; this is the display side of that. See App\Support\DisplayTime.
     */
    function shown(?CarbonInterface $moment): ?CarbonInterface
    {
        return DisplayTime::of($moment);
    }
}
