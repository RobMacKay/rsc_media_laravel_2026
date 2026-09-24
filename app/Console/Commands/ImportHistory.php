<?php

namespace App\Console\Commands;

use App\Actions\Import\ImportHistory as ImportHistoryAction;
use App\Enums\Currency;
use App\Exceptions\ImportException;
use App\Models\Invoice;
use App\Models\StudioSetting;
use Illuminate\Console\Command;

class ImportHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'history:import
        {path : The master CSV, one row per invoice or standing arrangement}
        {--dry-run : Work out what would be imported and print it, without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import clients, invoice history and standing arrangements from the master CSV';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = (new ImportHistoryAction(StudioSetting::current()))
                ->handle($this->argument('path'), $dryRun);
        } catch (ImportException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $invoices = $result['invoices'];

        if ($invoices->isEmpty() && $result['skipped'] === [] && $result['schedules']->isEmpty()) {
            $this->components->warn('That file has nothing in it to import.');

            return self::FAILURE;
        }

        $this->table(
            ['Client', 'Invoices', 'Total'],
            $invoices
                ->groupBy(fn (Invoice $invoice) => $invoice->team->name)
                ->map(fn ($rows, $name) => [$name, $rows->count(), $rows->first()->money((float) $rows->sum('amount'), 2)])
                ->sortBy(0)
                ->values(),
        );

        foreach ($result['totals'] as $code => $total) {
            $this->line('  <fg=gray>'.$code.'</> '.Currency::from($code)->format($total, 2));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Invoices imported', (string) $invoices->count());
        $this->components->twoColumnDetail('Standing arrangements', (string) $result['schedules']->count());

        if ($result['clients'] !== []) {
            $this->components->twoColumnDetail(
                'Clients opened <fg=gray>(nobody emailed)</>',
                (string) count($result['clients']),
            );
            $this->line('  <fg=gray>'.implode(', ', $result['clients']).'</>');
        }

        if ($result['skipped'] !== []) {
            $this->components->twoColumnDetail('Already here, left alone', (string) count($result['skipped']));
        }

        $this->components->twoColumnDetail('Payment dates', (string) $result['dated']);

        if ($result['undated'] !== []) {
            $this->components->twoColumnDetail('Paid, date unknown', (string) count($result['undated']));
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Nothing was written. Drop --dry-run to import for real.');

            return self::SUCCESS;
        }

        $this->components->info('Imported '.$invoices->count().' '.str('invoice')->plural($invoices->count()).'.');

        return self::SUCCESS;
    }
}
