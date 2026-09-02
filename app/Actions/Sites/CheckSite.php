<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\User;
use App\Notifications\SiteIsBackUp;
use App\Notifications\SiteIsDown;
use App\Support\Sites\Certificate;
use App\Support\Sites\CertificateInspector;
use App\Support\Sites\SshProbe;
use App\Support\Sites\SshResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The single place a site is checked, recorded and reported on.
 */
class CheckSite
{
    /**
     * How many checks in a row must fail before the client is emailed.
     *
     * One failed request is usually a blip. Two in a row, minutes apart, is
     * worth an email; anything less would cry wolf.
     */
    public const FAILURES_BEFORE_EMAIL = 2;

    public function __construct(
        private CertificateInspector $certificates,
        private SshProbe $ssh,
    ) {}

    /**
     * Check a site, write the result to its log, and email if it has gone down.
     */
    public function handle(Site $site): SiteCheck
    {
        [$status, $httpStatus, $responseMs, $error] = $this->request($site);

        $certificate = str_starts_with($site->url, 'https://')
            ? $this->certificates->inspect($site->host)
            : new Certificate(valid: false, error: __('The site is not served over https.'));

        $ssh = $site->ssh_enabled
            ? $this->ssh->probe($site->host, $site->ssh_port)
            : null;

        $check = $site->checks()->create([
            'checked_at' => now(),
            'status' => $status,
            'http_status' => $httpStatus,
            'response_ms' => $responseMs,
            'ssl_valid' => $certificate->valid,
            'ssl_expires_at' => $certificate->expiresAt,
            'ssh_ok' => $ssh?->reachable,
            'ssh_banner' => $ssh?->banner,
            'error' => $error ?? $certificate->error,
        ]);

        $this->record($site, $check, $certificate, $ssh);

        return $check;
    }

    /**
     * Make the request, and turn whatever happened into a result.
     *
     * @return array{0: SiteStatus, 1: int|null, 2: int|null, 3: string|null}
     */
    private function request(Site $site): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders(['User-Agent' => 'RSC Media site monitor'])
                ->timeout(15)
                ->connectTimeout(8)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($site->url);

            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            return $response->successful() || $response->redirect()
                ? [SiteStatus::Up, $response->status(), $elapsed, null]
                : [SiteStatus::Down, $response->status(), $elapsed, __('The site answered with :code.', ['code' => $response->status()])];
        } catch (Throwable $e) {
            return [
                SiteStatus::Down,
                null,
                (int) round((microtime(true) - $startedAt) * 1000),
                $e->getMessage(),
            ];
        }
    }

    /**
     * Fold the check into the site's current state, and email on a change.
     */
    private function record(Site $site, SiteCheck $check, Certificate $certificate, ?SshResult $ssh): void
    {
        $isUp = $check->status === SiteStatus::Up;

        $site->fill([
            'status' => $check->status,
            'http_status' => $check->http_status,
            'response_ms' => $check->response_ms,
            'ssl_valid' => $certificate->valid,
            'ssl_expires_at' => $certificate->expiresAt,
            'ssl_issuer' => $certificate->issuer,
            'ssh_ok' => $ssh?->reachable,
            'ssh_banner' => $ssh?->banner,
            'ssh_error' => $ssh?->error,
            'last_error' => $check->error,
            'last_checked_at' => $check->checked_at,
            'consecutive_failures' => $isUp ? 0 : $site->consecutive_failures + 1,
        ]);

        if ($isUp) {
            $site->last_up_at = $check->checked_at;
        } else {
            $site->last_down_at = $check->checked_at;
        }

        // One outage sends one email, and one all-clear when it comes back.
        $recovered = $isUp && $site->down_notified_at !== null;

        $shouldWarn = ! $isUp
            && $site->down_notified_at === null
            && $site->consecutive_failures >= self::FAILURES_BEFORE_EMAIL;

        if ($recovered) {
            $site->down_notified_at = null;
        }

        if ($shouldWarn) {
            $site->down_notified_at = now();
        }

        $site->save();

        if ($recovered) {
            $this->notify($site, new SiteIsBackUp($site));
        }

        if ($shouldWarn) {
            $this->notify($site, new SiteIsDown($site, $check));
        }
    }

    /**
     * Send a notification to everyone at the client who can act on it.
     */
    private function notify(Site $site, object $notification): void
    {
        // A site with no client is the studio's own, so the studio hears about it.
        $people = $site->team === null
            ? User::query()->where('is_admin', true)->get()
            : $site->team->members;

        if ($people->isEmpty()) {
            return;
        }

        Notification::send($people, $notification);
    }
}
