<?php

namespace App\Console\Commands;

use App\Actions\Billing\ImportInvoices as ImportInvoicesAction;
use App\Enums\Currency;
use App\Models\Invoice;
use App\Models\StudioSetting;
use Illuminate\Console\Command;
use RuntimeException;

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

        try {
            $result = (new ImportInvoicesAction(StudioSetting::current()))->handle(
                invoicesPath: $this->argument('path'),
                paymentsPath: $this->option('payments'),
                dryRun: $dryRun,
            );
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $invoices = $result['invoices'];

        if ($invoices->isEmpty() && $result['skipped'] === []) {
            $this->components->warn('That export has no invoices in it.');

            return self::FAILURE;
        }

        $this->table(
            ['Client', 'Invoices', 'Total'],
            $invoices
                ->groupBy(fn (Invoice $invoice) => $invoice->team->name)
                ->map(fn ($rows, $name) => [
                    $name,
                    $rows->count(),
                    $rows->first()->money($rows->sum('amount'), 2),
                ])
                ->sortBy(0)
                ->values(),
        );

        foreach ($result['totals'] as $code => $total) {
            $this->line('  <fg=gray>'.$code.'</> '.Currency::from($code)->format($total, 2));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Invoices imported', (string) $invoices->count());

        if ($result['teams'] !== []) {
            $this->components->twoColumnDetail(
                'Clients opened <fg=gray>(nobody emailed)</>',
                (string) count($result['teams']),
            );
            $this->line('  <fg=gray>'.implode(', ', $result['teams']).'</>');
        }

        if ($result['skipped'] !== []) {
            $this->components->twoColumnDetail(
                'Already here, left alone',
                (string) count($result['skipped']),
            );
        }

        $this->components->twoColumnDetail('Payment dates filled in', (string) $result['dated']);

        if ($result['undated'] !== []) {
            $this->components->twoColumnDetail(
                'Paid, date unknown',
                (string) count($result['undated']),
            );
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

        $this->components->info('Imported '.$invoices->count().' '.str('invoice')->plural($invoices->count()).'.');

        return self::SUCCESS;
    }
}
