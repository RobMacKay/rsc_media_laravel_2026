<?php

namespace App\Console\Commands;

use App\Actions\Billing\ChaseInvoices as ChaseInvoicesAction;
use App\Models\Invoice;
use Illuminate\Console\Command;

class ChaseInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:chase {--pretend : Show what would happen without marking or sending anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark unpaid invoices overdue and send the reminder that is due';

    /**
     * Execute the console command.
     */
    public function handle(ChaseInvoicesAction $chaser): int
    {
        if ($this->option('pretend')) {
            return $this->pretend();
        }

        $marked = $chaser->markOverdue();

        $this->components->info($marked->isEmpty()
            ? 'Nothing newly overdue.'
            : $marked->count().' invoice(s) marked overdue.');

        $reminded = $chaser->sendReminders();

        if ($reminded->isEmpty()) {
            $this->components->info('No reminders were due.');

            return self::SUCCESS;
        }

        $this->table(
            ['invoice', 'client', 'due', 'stage'],
            $reminded->map(fn (Invoice $invoice) => [
                $invoice->number,
                $invoice->team->name,
                $invoice->due_on->format('j M'),
                $invoice->reminder_stage?->value,
            ]),
        );

        $this->components->info($reminded->count().' reminder(s) sent.');

        return self::SUCCESS;
    }

    /**
     * Say what would go out for one invoice.
     *
     * Written out rather than as `?->value ?? '—'`: that reads fine but leans
     * on reading a property off null, which warns at runtime.
     */
    private function wouldSend(Invoice $invoice): string
    {
        if ($invoice->reminders_paused_at !== null) {
            return 'paused';
        }

        $stage = $invoice->reminderDue();

        return $stage === null ? '—' : $stage->value;
    }

    /**
     * Report what a real run would do, changing nothing.
     */
    private function pretend(): int
    {
        $rows = Invoice::query()
            ->whereNotIn('status', ['paid', 'draft'])
            ->with('team')
            ->get()
            ->map(fn (Invoice $invoice) => [
                $invoice->number,
                $invoice->team->name,
                $invoice->due_on->format('j M'),
                $invoice->daysPastDue().'d',
                $this->wouldSend($invoice),
            ])
            ->all();

        if ($rows === []) {
            $this->components->info('Nothing outstanding.');

            return self::SUCCESS;
        }

        $this->table(['invoice', 'client', 'due', 'past_due', 'would_send'], $rows);

        return self::SUCCESS;
    }
}
