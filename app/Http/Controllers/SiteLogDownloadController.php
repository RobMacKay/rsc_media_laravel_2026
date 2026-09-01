<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteLogDownloadController extends Controller
{
    /**
     * Hand back this site's check history as a CSV.
     *
     * Streamed a chunk at a time, because a site checked every fifteen minutes
     * builds about 35,000 rows a year and loading that to build a string would
     * be a needless way to run out of memory.
     */
    public function __invoke(Request $request, Site $site): StreamedResponse
    {
        abort_unless($site->team_id === $request->user()->current_team_id, 404);

        $filename = $site->host.'-log-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($site) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['checked_at', 'status', 'http_status', 'response_ms', 'ssl_valid', 'ssl_expires_at', 'ssh_ok', 'ssh_banner', 'error']);

            $site->checks()->reorder('checked_at')->chunk(500, function ($checks) use ($handle) {
                foreach ($checks as $check) {
                    fputcsv($handle, [
                        $check->checked_at->toIso8601String(),
                        $check->status->value,
                        $check->http_status,
                        $check->response_ms,
                        $check->ssl_valid === null ? '' : ($check->ssl_valid ? 'yes' : 'no'),
                        $check->ssl_expires_at?->toDateString(),
                        $check->ssh_ok === null ? '' : ($check->ssh_ok ? 'yes' : 'no'),
                        $check->ssh_banner,
                        $check->error,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
