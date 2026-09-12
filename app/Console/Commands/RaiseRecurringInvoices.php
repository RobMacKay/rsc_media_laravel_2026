<?php

namespace App\Console\Commands;

use App\Actions\Billing\RaiseRecurringInvoices as RaiseRecurringInvoicesAction;
use App\Models\StudioSetting;
use Illuminate\Console\Command;

class RaiseRecurringInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:raise-recurring {--pretend : List what would be raised without raising it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Raise this month\'s invoices for standing monthly arrangements whose billing day has come round';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = new RaiseRecurringInvoicesAction(StudioSetting::current());

        $due = $action->due();

        if ($due->isEmpty()) {
            $this->info('Nothing due — every standing arrangement is settled for '.now()->format('F').'.');

            return self::SUCCESS;
        }

        if ($this->option('pretend')) {
            $this->table(
                ['Client', 'For', 'Amount', 'Bills on', 'Record'],
                $due->map(fn ($schedule) => [
                    $schedule->team->name,
                    $schedule->note,
                    $schedule->team->money($schedule->amount, 2),
                    $schedule->billingDateIn(now())->format('j M Y'),
                    $schedule->record_only ? 'yes' : '',
                ]),
            );

            return self::SUCCESS;
        }

        $raised = $action->handle();

        $this->table(
            ['Invoice', 'Client', 'Amount', 'Due'],
            $raised->map(fn ($invoice) => [
                $invoice->number,
                $invoice->team->name,
                $invoice->money($invoice->amount, 2),
                $invoice->record_only ? 'record' : $invoice->due_on->format('j M Y'),
            ]),
        );

        $this->info('Raised '.$raised->count().' recurring '.str('invoice')->plural($raised->count()).'.');

        return self::SUCCESS;
    }
}
