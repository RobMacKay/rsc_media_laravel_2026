<?php

namespace App\Console\Commands;

use App\Actions\Billing\RaisePlanInvoices as RaisePlanInvoicesAction;
use App\Models\StudioSetting;
use Illuminate\Console\Command;

class RaisePlanInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:raise-plans {--pretend : List what would be raised without raising it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Raise this month\'s support plan invoices for every client on a plan';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = new RaisePlanInvoicesAction(StudioSetting::current());

        $due = $action->due();

        if ($due->isEmpty()) {
            $this->info('Nothing due — every client on a plan has been invoiced for '.now()->format('F').'.');

            return self::SUCCESS;
        }

        if ($this->option('pretend')) {
            $this->table(
                ['Client', 'Plan', 'Amount'],
                $due->map(fn ($team) => [$team->name, $team->plan->name, '£'.number_format($team->plan->price)]),
            );

            return self::SUCCESS;
        }

        $raised = $action->handle();

        $this->table(
            ['Invoice', 'Client', 'Amount', 'Due'],
            $raised->map(fn ($invoice) => [
                $invoice->number,
                $invoice->team->name,
                '£'.number_format($invoice->amount),
                $invoice->due_on->format('j M Y'),
            ]),
        );

        $this->info('Raised '.$raised->count().' plan '.str('invoice')->plural($raised->count()).'.');

        return self::SUCCESS;
    }
}
