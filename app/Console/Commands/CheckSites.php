<?php

namespace App\Console\Commands;

use App\Actions\Sites\CheckSite;
use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Console\Command;

class CheckSites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sites:check {--site= : Check one site by id, rather than all of them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check every monitored site and record the result';

    /**
     * Execute the console command.
     */
    public function handle(CheckSite $checker): int
    {
        $sites = Site::query()
            ->where('is_active', true)
            ->when($this->option('site'), fn ($query, $id) => $query->whereKey($id))
            ->with('team')
            ->get();

        if ($sites->isEmpty()) {
            $this->components->info('No sites to check.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($sites as $site) {
            $check = $checker->handle($site);

            $rows[] = [
                $site->host,
                $check->status->label(),
                $check->http_status ?? '—',
                $check->response_ms === null ? '—' : $check->response_ms.'ms',
                $site->sslLabel(),
            ];
        }

        $this->table(['site', 'status', 'code', 'time', 'certificate'], $rows);

        $down = $sites->where('status', SiteStatus::Down)->count();

        $this->components->info($down === 0
            ? 'All '.$sites->count().' sites answered.'
            : $down.' of '.$sites->count().' sites did not answer.');

        return self::SUCCESS;
    }
}
