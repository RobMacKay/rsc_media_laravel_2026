<?php

namespace App\Actions\Billing;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\ImportException;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Support\BusinessName;
use App\Support\CsvReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bring the studio's invoice history over from Invoice Ninja.
 *
 * This is deliberately not RaiseInvoice, and that is the one exception to
 * "every invoice comes through RaiseInvoice". RaiseInvoice exists to make new
 * invoices consistent: it stamps the next number, today's date and the
 * studio's current VAT rate. An import has to keep the number, the dates and
 * the status that were actually issued years ago, so putting it through
 * RaiseInvoice would mean adding overrides for every one of those and
 * hollowing out the guarantee RaiseInvoice is there to give.
 *
 * Nothing here touches RaiseInvoice's territory: imported rows all carry the
 * IN- prefix, so they cannot move the studio's own numbering along.
 */
class ImportInvoices
{
    /**
     * Invoice Ninja's status names, mapped onto ours.
     */
    /**
     * The columns the invoice export cannot be read without.
     */
    private const Required = [
        'Invoice Invoice Number',
        'Client Name',
        'Invoice Date',
        'Invoice Amount',
    ];

    private const Statuses = [
        'Draft' => InvoiceStatus::Draft,
        'Sent' => InvoiceStatus::Sent,
        'Partial/Deposit' => InvoiceStatus::Partial,
        'Paid' => InvoiceStatus::Paid,
    ];

    /**
     * The clients already on the books, primed once rather than looked up per
     * row, and added to as the import opens new ones.
     *
     * @var Collection<int, Team>
     */
    private Collection $teams;

    /**
     * The report being read, which owns the defensive column handling.
     */
    private CsvReport $report;

    public function __construct(private StudioSetting $settings) {}

    /**
     * Import the invoice export, optionally backfilling payment dates from the
     * separate payments export.
     *
     * Safe to run more than once: an invoice whose number is already here is
     * left alone, so a re-run after a mapping fix adds what was missing rather
     * than duplicating what was not. Payment dates are backfilled on every
     * run, so the payments export can arrive later than the invoices did.
     *
     * @return array{
     *     invoices: Collection<int, Invoice>,
     *     skipped: list<string>,
     *     teams: list<string>,
     *     dated: int,
     *     undated: list<string>,
     *     totals: array<string, float>,
     * }
     */
    public function handle(string $invoicesPath, ?string $paymentsPath = null, bool $dryRun = false): array
    {
        $report = CsvReport::read($invoicesPath)->require('Invoice', self::Required);
        $rows = $report->rows();
        $this->report = $report;
        $this->teams = Team::withTrashed()->get();
        $paidDates = $paymentsPath === null ? [] : $this->paymentDates($paymentsPath);

        $imported = collect();
        $skipped = [];
        $teamsCreated = [];
        $undated = [];
        $dated = 0;
        $totals = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $reference = trim($row['Invoice Invoice Number']);

                // A row with no number of its own cannot be told apart from
                // any other, so it is not something to guess at.
                if ($reference === '') {
                    $skipped[] = 'row '.$line;

                    continue;
                }

                $number = Invoice::ImportPrefix.'-'.$reference;
                $existing = Invoice::query()->where('number', $number)->first();

                if ($existing !== null) {
                    $skipped[] = $number;

                    if ($this->applyPaymentDate($existing, $paidDates)) {
                        $dated++;
                    }

                    continue;
                }

                $team = $this->team($row, $teamsCreated);
                $invoice = $this->build($row, $team, $number, $paidDates, $line);

                if ($invoice->paid_at !== null) {
                    $dated++;
                } elseif ($invoice->status === InvoiceStatus::Paid) {
                    $undated[] = $number;
                }

                $invoice->save();

                $imported->push($invoice);

                $code = $team->currency->value;
                $totals[$code] = ($totals[$code] ?? 0) + $invoice->amount;
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return [
            'invoices' => $imported,
            'skipped' => $skipped,
            'teams' => $teamsCreated,
            'dated' => $dated,
            'undated' => $undated,
            'totals' => $totals,
        ];
    }

    /**
     * Reduce a result into plain values fit for rendering.
     *
     * Both the command and the import screen render from this, so the figure
     * the studio reconciles against on screen and the one in the terminal come
     * from the same arithmetic. It also has to be plain arrays rather than the
     * models themselves: a dry run has rolled its clients back by the time
     * anything reports on it, and the screen holds this across a request.
     *
     * @param  array{
     *     invoices: Collection<int, Invoice>,
     *     skipped: list<string>,
     *     teams: list<string>,
     *     dated: int,
     *     undated: list<string>,
     *     totals: array<string, float>,
     * }  $result
     * @return array{
     *     clients: list<array{name: string, invoices: int, total: string}>,
     *     totals: list<array{currency: string, total: string}>,
     *     imported: int,
     *     opened: list<string>,
     *     skipped: int,
     *     dated: int,
     *     undated: int,
     * }
     */
    public function summarise(array $result): array
    {
        $invoices = $result['invoices'];

        return [
            'clients' => array_values($invoices
                ->groupBy(fn (Invoice $invoice) => $invoice->team->name)
                ->map(fn (Collection $rows, string $name) => [
                    'name' => $name,
                    'invoices' => $rows->count(),
                    'total' => $rows->first()->money((float) $rows->sum('amount'), 2),
                ])
                ->sortBy('name')
                ->all()),
            'totals' => array_values(collect($result['totals'])
                ->map(fn (float $total, string $code) => [
                    'currency' => $code,
                    'total' => Currency::from($code)->format($total, 2),
                ])
                ->sortKeys()
                ->all()),
            'imported' => $invoices->count(),
            'opened' => $result['teams'],
            'skipped' => count($result['skipped']),
            'dated' => $result['dated'],
            'undated' => count($result['undated']),
        ];
    }

    /**
     * Build one invoice from an export row, without saving it.
     *
     * @param  array<string, string>  $row
     * @param  array<string, Carbon>  $paidDates
     */
    private function build(array $row, Team $team, string $number, array $paidDates, int $line): Invoice
    {
        $status = self::Statuses[trim($this->report->value($row, 'Invoice Status'))] ?? InvoiceStatus::Sent;
        $issued = $this->date($this->report->value($row, 'Invoice Date'), $line, 'Invoice Date');

        $invoice = new Invoice([
            'number' => $number,
            'team_id' => $team->id,
            // Everything comes in as ad hoc. The 43 rows Invoice Ninja had on
            // a recurring template must not arrive as InvoiceType::Plan, or
            // RaisePlanInvoices would read them as this month's plan invoice
            // already being raised.
            'type' => InvoiceType::AdHoc,
            'note' => $this->note($this->report->value($row, 'Invoice Public Notes')),
            'amount' => $this->money($this->report->value($row, 'Invoice Amount')),
            'paid_to_date' => $this->money($this->report->value($row, 'Invoice Paid to Date')),
            'discount' => $this->money($this->report->value($row, 'Invoice Discount')),
            'po_number' => $this->text($this->report->value($row, 'Invoice PO Number')),
            // Whatever the client and the accountant already have on file.
            'external_reference' => trim($row['Invoice Invoice Number']),
            // No row in the export carries any tax: the studio is not
            // registered, and an imported invoice never charged VAT.
            'vat_rate' => 0,
            'currency' => $team->currency,
            'issued_on' => $issued,
            'due_on' => $this->dueDate($row, $team, $issued, $line),
            'status' => $status,
            // Imported history is brought over for the record, not to restart
            // collections. Whatever chasing these needed happened in the old
            // system, and an invoice from 2025 must not fire a final notice at
            // a client the moment somebody sets a billing address on them.
            // The studio can unmute any one it does still want chased.
            'reminders_paused_at' => $status->isOutstanding() ? now() : null,
        ]);

        $this->applyPaymentDate($invoice, $paidDates);

        // Held on the object rather than looked up later: it saves a query per
        // row, and a dry run rolls the clients back before anything reporting
        // on the result gets to ask which client a row belonged to.
        $invoice->setRelation('team', $team);

        return $invoice;
    }

    /**
     * Set the date an invoice was settled, if the payments export gave one.
     *
     * Left null rather than guessed when it did not. An invoice that says it
     * was paid on a date it was not is worse than one that admits it does not
     * know, and chasing only ever looks at what is outstanding.
     *
     * Only a fully settled invoice gets a date. The payments export lists the
     * part payment against an invoice that is still owed, and stamping that
     * date on would read as the whole thing having been paid off.
     *
     * @param  array<string, Carbon>  $paidDates
     */
    private function applyPaymentDate(Invoice $invoice, array $paidDates): bool
    {
        $reference = $invoice->external_reference;

        if ($invoice->status !== InvoiceStatus::Paid || $invoice->paid_at !== null) {
            return false;
        }

        if ($reference === null || ! isset($paidDates[$reference])) {
            return false;
        }

        $invoice->paid_at = $paidDates[$reference];

        if (! $invoice->exists || $invoice->save()) {
            return true;
        }

        return false;
    }

    /**
     * Get the client this row belongs to, opening one if it is new.
     *
     * Deliberately not CreateClient: that makes a user and emails them a
     * set-your-password link, and some of these contacts last heard from the
     * studio in 2022. A client comes in as the business only, and whoever is
     * still working with the studio gets invited by hand afterwards.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $created
     */
    private function team(array $row, array &$created): Team
    {
        $name = trim($row['Client Name']);
        $currency = Currency::tryFrom(trim($this->report->value($row, 'Client Currency'))) ?? Currency::Base;

        $team = $this->teams->first(fn (Team $team) => BusinessName::matches($team->name, $name));

        if ($team !== null) {
            return $team;
        }

        $team = Team::create(['name' => $name, 'currency' => $currency]);

        $this->teams->push($team);
        $created[] = $name;

        return $team;
    }

    /**
     * Get the date an invoice fell due.
     *
     * 57 rows have no due date at all, so they fall back to the client's
     * payment terms from the date they were issued.
     *
     * @param  array<string, string>  $row
     */
    private function dueDate(array $row, Team $team, Carbon $issued, int $line): Carbon
    {
        $due = $this->text($this->report->value($row, 'Invoice Due Date'));

        return $due === null
            ? $issued->copy()->addDays($team->effectivePaymentTerms($this->settings))
            : $this->date($due, $line, 'Invoice Due Date');
    }

    /**
     * Read the payment dates keyed by the invoice number they settled.
     *
     * The payments export is a different report from the invoice one, and the
     * two look alike enough to mix up: the invoice report filtered to Paid has
     * the same columns and none of the dates. So the date column has to be
     * named for a payment outright. Nothing here falls back to another date —
     * an issue date quietly written down as the day the money arrived is worse
     * than admitting the date is not known.
     *
     * @return array<string, Carbon>
     */
    private function paymentDates(string $path): array
    {
        $report = CsvReport::read($path, 'payments');
        $rows = $report->rows();

        if ($rows === []) {
            return [];
        }

        $dateColumn = $report->column(['payment date', 'date paid', 'paid on', 'transaction date']);
        $numberColumn = $report->column(['invoice invoice number', 'invoice number', 'number']);

        if ($dateColumn === null || $numberColumn === null) {
            throw ImportException::noPaymentDates($report->columns());
        }

        $dates = [];

        foreach ($rows as $row) {
            $number = trim($row[$numberColumn]);
            $date = $this->text($row[$dateColumn]);

            if ($number === '' || $date === null) {
                continue;
            }

            $paid = Carbon::parse($date);

            // An invoice settled in instalments has a row per payment, and the
            // date it was settled is the last of them. Taken as a maximum
            // rather than by reading whichever row came last, so the answer
            // does not depend on how the export happened to be ordered.
            if (! isset($dates[$number]) || $paid->greaterThan($dates[$number])) {
                $dates[$number] = $paid;
            }
        }

        return $dates;
    }

    /**
     * Parse a date column, saying which row is wrong rather than throwing a
     * parser error at whoever uploaded the file.
     */
    private function date(string $value, int $line, string $column): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw ImportException::badDate($line, $column, $value);
        }
    }

    /**
     * Parse a money column, which is formatted with thousands separators.
     */
    private function money(string $value): float
    {
        return (float) str_replace(',', '', trim($value));
    }

    /**
     * Get a trimmed value, or null when the column was empty.
     */
    private function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Turn Invoice Ninja's rich-text note into plain text.
     *
     * Links are kept as readable URLs, because several notes are nothing but a
     * link to the project folder and stripping the tags would throw the
     * address away and leave the sentence pointing at nothing.
     */
    private function note(string $html): ?string
    {
        $text = preg_replace(
            '/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',
            '$2 <$1>',
            $html,
        ) ?? $html;

        $text = preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        // A link whose text was already the URL reads as "url <url>".
        $text = preg_replace('/(\S+) <\1>/', '$1', $text) ?? $text;

        // Collapse the runs of blank lines and spaces the HTML leaves behind,
        // without flattening a genuine multi-line work log into one paragraph.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ([)\].,;:])/', '$1', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*\n\s*/', "\n\n", $text) ?? $text;

        return $this->text($text);
    }
}
