<?php

namespace App\Actions\Billing;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
        $rows = $this->read($invoicesPath);
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
            foreach ($rows as $row) {
                $number = Invoice::ImportPrefix.'-'.trim($row['Invoice Invoice Number']);
                $existing = Invoice::query()->where('number', $number)->first();

                if ($existing !== null) {
                    $skipped[] = $number;

                    if ($this->applyPaymentDate($existing, $paidDates)) {
                        $dated++;
                    }

                    continue;
                }

                $team = $this->team($row, $teamsCreated);
                $invoice = $this->build($row, $team, $number, $paidDates);

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
        } catch (\Throwable $e) {
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
     * Build one invoice from an export row, without saving it.
     *
     * @param  array<string, string>  $row
     * @param  array<string, Carbon>  $paidDates
     */
    private function build(array $row, Team $team, string $number, array $paidDates): Invoice
    {
        $status = self::Statuses[trim($row['Invoice Status'])] ?? InvoiceStatus::Sent;
        $issued = Carbon::parse($row['Invoice Date']);

        $invoice = new Invoice([
            'number' => $number,
            'team_id' => $team->id,
            // Everything comes in as ad hoc. The 43 rows Invoice Ninja had on
            // a recurring template must not arrive as InvoiceType::Plan, or
            // RaisePlanInvoices would read them as this month's plan invoice
            // already being raised.
            'type' => InvoiceType::AdHoc,
            'note' => $this->note($row['Invoice Public Notes']),
            'amount' => $this->money($row['Invoice Amount']),
            'paid_to_date' => $this->money($row['Invoice Paid to Date']),
            'discount' => $this->money($row['Invoice Discount']),
            'po_number' => $this->text($row['Invoice PO Number']),
            // Whatever the client and the accountant already have on file.
            'external_reference' => trim($row['Invoice Invoice Number']),
            // No row in the export carries any tax: the studio is not
            // registered, and an imported invoice never charged VAT.
            'vat_rate' => 0,
            'currency' => $team->currency,
            'issued_on' => $issued,
            'due_on' => $this->dueDate($row, $team, $issued),
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
        $currency = Currency::tryFrom(trim($row['Client Currency'])) ?? Currency::Base;

        $team = $this->teams->first(fn (Team $team) => $this->matches($team->name, $name));

        if ($team !== null) {
            return $team;
        }

        $team = Team::create(['name' => $name, 'currency' => $currency]);

        $this->teams->push($team);
        $created[] = $name;

        return $team;
    }

    /**
     * Determine whether two client names are the same business.
     *
     * Loose enough to match "Mearns and Gill" to "Mearns & Gill" so an import
     * does not open a second record for a client already on the books.
     */
    private function matches(string $one, string $other): bool
    {
        $normalise = fn (string $name) => Str::of($name)
            ->lower()
            ->replace('&', 'and')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        return $normalise($one) === $normalise($other);
    }

    /**
     * Get the date an invoice fell due.
     *
     * 57 rows have no due date at all, so they fall back to the client's
     * payment terms from the date they were issued.
     *
     * @param  array<string, string>  $row
     */
    private function dueDate(array $row, Team $team, Carbon $issued): Carbon
    {
        $due = $this->text($row['Invoice Due Date']);

        return $due === null
            ? $issued->copy()->addDays($team->effectivePaymentTerms($this->settings))
            : Carbon::parse($due);
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
        $rows = $this->read($path);

        if ($rows === []) {
            return [];
        }

        $columns = array_keys($rows[0]);
        $dateColumn = $this->column($columns, ['payment date', 'date paid', 'paid on', 'transaction date']);
        $numberColumn = $this->column($columns, ['invoice invoice number', 'invoice number', 'number']);

        if ($dateColumn === null || $numberColumn === null) {
            throw new RuntimeException(
                'That file has no payment dates in it. In Invoice Ninja the report type has to be '
                .'"Payment", not "Invoice" — the invoice report filtered to Paid carries the same '
                .'columns and no payment date at all ("Paid to Date" is an amount, not a date). '
                .'Columns found: '.implode(', ', $columns).'.'
            );
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
     * Find the column with exactly one of the given names, case aside.
     *
     * Matched whole rather than loosely: "Invoice Paid to Date" is an amount
     * and "Invoice Date" is when the invoice was raised, and either one
     * pattern-matched into a payment date would silently misdate the books.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $candidates
     */
    private function column(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($columns as $column) {
                if (Str::of($column)->lower()->squish()->toString() === $candidate) {
                    return $column;
                }
            }
        }

        return null;
    }

    /**
     * Read a CSV into rows keyed by column name.
     *
     * @return list<array<string, string>>
     */
    private function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read [{$path}].");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            // The export ends with a blank line, and a short row would
            // silently shift every value along by one.
            if ($line === [null] || count(array_filter($line, fn ($value) => (string) $value !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }

        fclose($handle);

        return $rows;
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
