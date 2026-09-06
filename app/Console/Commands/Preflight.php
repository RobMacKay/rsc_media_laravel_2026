<?php

namespace App\Console\Commands;

use App\Jobs\PreflightPing;
use App\Support\Turnstile;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Answer the one question a fresh server cannot: will this actually work?
 *
 * Nearly everything here fails silently in production — no worker means mail
 * queues up for ever with no error, a log mailer swallows it, an unrun
 * scheduler simply never chases anybody. Better to be told.
 */
class Preflight extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:preflight
                            {--wait=15 : Seconds to give a queue worker to answer}
                            {--skip-probe : Do not put a test job on the queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check this server can actually send mail, run jobs and keep to the schedule';

    /**
     * How long since the scheduler last ran before it counts as stopped.
     */
    private const SCHEDULER_GRACE_MINUTES = 5;

    /**
     * The findings, worst last so the summary reads right.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $results = [];

    /**
     * Execute the console command.
     */
    public function handle(Turnstile $turnstile): int
    {
        $this->checkMail();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkSignedLinks();
        $this->checkPrivateStorage();
        $this->checkTurnstile($turnstile);

        $this->newLine();
        $this->table(['check', 'result', 'detail'], $this->results);

        $failures = $this->countWith('FAIL');
        $warnings = $this->countWith('WARN');

        if ($failures > 0) {
            $this->components->error("{$failures} of these will stop the site working. Fix them before launch.");

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->components->warn("Nothing is broken, but {$warnings} thing(s) are worth a look.");

            return self::SUCCESS;
        }

        $this->components->info('Everything this application needs is in place.');

        return self::SUCCESS;
    }

    /**
     * Count the findings with a given result.
     */
    private function countWith(string $result): int
    {
        return count(array_filter($this->results, fn (array $row) => $row[1] === $result));
    }

    /**
     * Check mail will leave the building.
     */
    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->add('mail transport', $this->isProduction() ? 'FAIL' : 'WARN',
                "'{$mailer}' does not send anything. Set MAIL_MAILER.");
        } else {
            $this->add('mail transport', 'PASS', $mailer);
        }

        if ($from === '' || Str::endsWith($from, ['example.com', 'example.test'])) {
            $this->add('from address', 'FAIL', "MAIL_FROM_ADDRESS is '{$from}'.");

            return;
        }

        $this->add('from address', 'PASS', $from.' (must be a domain the transport may send from)');
    }

    /**
     * Check jobs are actually being picked up.
     *
     * Everything this application emails is queued, so a stopped worker is
     * indistinguishable from a working site until somebody complains.
     */
    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->add('queue', $this->isProduction() ? 'FAIL' : 'WARN',
                'sync runs jobs in the request. Mail will send, but slowly and in front of the visitor.');

            return;
        }

        $this->add('queue connection', 'PASS', $connection);
        $this->reportFailedJobs();

        if ($this->option('skip-probe')) {
            $this->add('queue worker', 'WARN', 'Not checked (--skip-probe).');

            return;
        }

        $this->probeWorker();
    }

    /**
     * Put a job on the queue and see whether anything picks it up.
     */
    private function probeWorker(): void
    {
        $token = (string) Str::uuid();
        $seconds = max(1, (int) $this->option('wait'));

        try {
            PreflightPing::dispatch($token);
        } catch (Throwable $e) {
            $this->add('queue worker', 'FAIL', 'Could not queue a job: '.$e->getMessage());

            return;
        }

        $this->components->task("Waiting up to {$seconds}s for a worker", function () use ($token, $seconds) {
            $until = now()->addSeconds($seconds);

            while (now()->lessThan($until)) {
                if (Cache::has(PreflightPing::keyFor($token))) {
                    return true;
                }

                usleep(500_000);
            }

            return false;
        });

        if (Cache::pull(PreflightPing::keyFor($token))) {
            $this->add('queue worker', 'PASS', 'A worker answered.');

            return;
        }

        $this->add('queue worker', 'FAIL',
            "Nothing picked the job up in {$seconds}s. Without a worker, no email ever leaves.");
    }

    /**
     * Report anything that has already failed on the queue.
     */
    private function reportFailedJobs(): void
    {
        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return;
        }

        $this->add('failed jobs', $failed === 0 ? 'PASS' : 'WARN',
            $failed === 0 ? 'None.' : "{$failed} waiting. Read them with queue:failed.");
    }

    /**
     * Check the scheduler is running, using the mark it leaves every minute.
     */
    private function checkScheduler(): void
    {
        $lastRun = Cache::get('scheduler.last_run');

        if (! is_string($lastRun)) {
            $this->add('scheduler', 'FAIL',
                'No sign it has ever run. Site checks and invoice chases depend on it.');

            return;
        }

        $ranAt = Carbon::parse($lastRun);
        $ago = $ranAt->diffForHumans();

        if ($ranAt->lessThan(now()->subMinutes(self::SCHEDULER_GRACE_MINUTES))) {
            $this->add('scheduler', 'FAIL', "Last ran {$ago}. It has stopped.");

            return;
        }

        $this->add('scheduler', 'PASS', "Last ran {$ago}.");
    }

    /**
     * Check signed links will survive being opened.
     *
     * A signature covers the scheme and host, so a welcome link generated
     * against the wrong APP_URL is refused the moment somebody clicks it.
     */
    private function checkSignedLinks(): void
    {
        $url = (string) config('app.url');

        if ($this->isProduction() && ! Str::startsWith($url, 'https://')) {
            $this->add('signed links', 'FAIL',
                "APP_URL is '{$url}'. Welcome and reset links will 403 when opened over https.");

            return;
        }

        $this->add('signed links', 'PASS', $url);
    }

    /**
     * Check attachments have somewhere private to live.
     */
    private function checkPrivateStorage(): void
    {
        try {
            $probe = 'preflight/'.Str::uuid().'.txt';

            Storage::disk('local')->put($probe, 'ok');
            $readBack = Storage::disk('local')->get($probe) === 'ok';
            Storage::disk('local')->delete($probe);

            $this->add('private storage', $readBack ? 'PASS' : 'FAIL',
                $readBack ? 'Writable.' : 'Wrote a file but could not read it back.');
        } catch (Throwable $e) {
            $this->add('private storage', 'FAIL', $e->getMessage());
        }
    }

    /**
     * Check bot protection, which is optional but easy to forget.
     */
    private function checkTurnstile(Turnstile $turnstile): void
    {
        if ($turnstile->enabled()) {
            $this->add('bot protection', 'PASS', 'Turnstile keys are set.');

            return;
        }

        $this->add('bot protection', $this->isProduction() ? 'WARN' : 'PASS',
            $this->isProduction()
                ? 'No Turnstile keys, so the public forms are unguarded.'
                : 'Off, as expected outside production.');
    }

    /**
     * Determine whether this is the live site.
     */
    private function isProduction(): bool
    {
        return app()->isProduction();
    }

    /**
     * Record a finding.
     */
    private function add(string $check, string $result, string $detail): void
    {
        $this->results[] = [$check, $result, $detail];
    }
}
