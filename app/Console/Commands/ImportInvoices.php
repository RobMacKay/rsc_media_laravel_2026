<?php

namespace App\Console\Commands;

use App\Actions\Billing\ImportInvoices as ImportInvoicesAction;
use App\Exceptions\ImportException;
use App\Models\StudioSetting;
use Illuminate\Console\Command;

class ImportInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:import
        {path : The Invoice Ninja invoice export, as CSV}
        {--payments= : The separate payments export, to fill in when each invoice was settled}
        {--dry-run : Work out what would be imported and print it, without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import invoice history from an Invoice Ninja CSV export';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $action = new ImportInvoicesAction(StudioSetting::current());

        try {
            $result = $action->handle(
                invoicesPath: $this->argument('path'),
                paymentsPath: $this->option('payments'),
                dryRun: $dryRun,
            );
        } catch (ImportException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = $action->summarise($result);

        if ($summary['imported'] === 0 && $summary['skipped'] === 0) {
            $this->components->warn('That export has no invoices in it.');

            return self::FAILURE;
        }

        $this->table(
            ['Client', 'Invoices', 'Total'],
            array_map(array_values(...), $summary['clients']),
        );

        foreach ($summary['totals'] as $total) {
            $this->line('  <fg=gray>'.$total['currency'].'</> '.$total['total']);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Invoices imported', (string) $summary['imported']);

        if ($summary['opened'] !== []) {
            $this->components->twoColumnDetail(
                'Clients opened <fg=gray>(nobody emailed)</>',
                (string) count($summary['opened']),
            );
            $this->line('  <fg=gray>'.implode(', ', $summary['opened']).'</>');
        }

        if ($summary['skipped'] > 0) {
            $this->components->twoColumnDetail('Already here, left alone', (string) $summary['skipped']);
        }

        $this->components->twoColumnDetail('Payment dates filled in', (string) $summary['dated']);

        if ($summary['undated'] > 0) {
            $this->components->twoColumnDetail('Paid, date unknown', (string) $summary['undated']);
            $this->line(
                '  <fg=gray>Export the Payment report from Invoice Ninja and re-run with --payments '
                .'to fill these in. Nothing else will be touched.</>'
            );
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Nothing was written. Drop --dry-run to import for real.');

            return self::SUCCESS;
        }

        $this->components->info(
            'Imported '.$summary['imported'].' '.str('invoice')->plural($summary['imported']).'.'
        );

        return self::SUCCESS;
    }
}
