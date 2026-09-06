<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A job that does nothing but prove a worker picked it up.
 *
 * Every email this application sends goes through the queue, so "is a worker
 * running" is the difference between the site working and silently sending
 * nothing at all. Counting pending jobs cannot tell the two apart; getting one
 * back can.
 */
class PreflightPing implements ShouldQueue
{
    use Queueable;

    /**
     * How long the answer stays readable.
     */
    public const TTL = 300;

    public function __construct(public string $token) {}

    /**
     * Get the cache key an answer is written to.
     */
    public static function keyFor(string $token): string
    {
        return "preflight:ping:{$token}";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cache::put(self::keyFor($this->token), now()->toIso8601String(), self::TTL);
    }
}
