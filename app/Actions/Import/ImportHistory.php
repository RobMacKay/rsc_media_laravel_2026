<?php

namespace App\Actions\Import;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\ImportException;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Support\BusinessName;
use App\Support\CsvReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bring the studio's history in from one master file.
 *
 * Deliberately knows nothing about Invoice Ninja, or any other system. Whatever
 * the studio is moving from, the reconciling of its exports happens once,
 * outside the application, and what arrives here is a single file in one
 * documented shape. The alternative — teaching the importer every quirk of
 * every report a billing system happens to produce — leaves those quirks in
 * the codebase long after the migration everyone has forgotten about.
 *
 * Every row carries its client, so the file is readable in a spreadsheet and
 * needs no lookup between sheets. A row is either an `invoice` (one raised, or
 * a record of work somebody else billed) or a `schedule` (a standing monthly
 * arrangement).
 */
class ImportHistory
{
    /**
     * The columns the file cannot be read without.
     */
    private const Required = ['row_type', 'client', 'amount'];

    /**
     * What a row can be.
     */
    private const Invoice = 'invoice';

    private const Schedule = 'schedule';

    public function __construct(private StudioSetting $settings) {}

    /**
     * Import the file.
     *
     * Safe to run more than once: an invoice whose number is already here is
     * left alone, a schedule the client already has is left alone, and contact
     * details are only written where the field is blank — the studio may have
     * corrected something by hand and a re-run must not put the old value back.
     *
     * @return array{
     *     invoices: Collection<int, Invoice>,
     *     schedules: Collection<int, RecurringInvoice>,
     *     clients: list<string>,
     *     skipped: list<string>,
     *     dated: int,
     *     undated: list<string>,
     *     totals: array<string, float>,
     * }
     */
    public function handle(string $path, bool $dryRun = false): array
    {
        $rows = CsvReport::read($path, 'file')->require('master', self::Required)->rows();

        $teams = Team::withTrashed()->get();
        $invoices = collect();
        $schedules = collect();
        $clients = [];
        $skipped = [];
        $undated = [];
        $dated = 0;
        $totals = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $type = strtolower(trim($this->value($row, 'row_type')));

                if (! in_array($type, [self::Invoice, self::Schedule], true)) {
                    throw ImportException::badRowType($line, $this->value($row, 'row_type'));
                }

                $team = $this->client($row, $line, $teams, $clients);

                if ($type === self::Schedule) {
                    $schedule = $this->schedule($row, $team, $line);

                    $schedule === null ? $skipped[] = 'schedule for '.$team->name : $schedules->push($schedule);

                    continue;
                }

                $number = trim($this->value($row, 'number'));

                if ($number === '') {
                    throw ImportException::missingValue($line, 'number');
                }

                if (Invoice::query()->where('number', $number)->exists()) {
                    $skipped[] = $number;

                    continue;
                }

                $invoice = $this->invoice($row, $team, $number, $line);

                if ($invoice->paid_at !== null) {
                    $dated++;
                } elseif ($invoice->status === InvoiceStatus::Paid) {
                    $undated[] = $number;
                }

                $invoice->save();
                $invoice->setRelation('team', $team);
                $invoices->push($invoice);

                $code = $team->currency->value;
                $totals[$code] = ($totals[$code] ?? 0) + $invoice->amount;
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return [
            'invoices' => $invoices,
            'schedules' => $schedules,
            'clients' => $clients,
            'skipped' => $skipped,
            'dated' => $dated,
            'undated' => $undated,
            'totals' => $totals,
        ];
    }

    /**
     * Get the client this row belongs to, opening the business if it is new.
     *
     * Never creates a user and never emails anybody: these are businesses with
     * history and no people until the studio invites them in from the settings
     * screen. That is what makes it safe to import a client last contacted
     * years ago.
     *
     * @param  array<string, string>  $row
     * @param  Collection<int, Team>  $teams
     * @param  list<string>  $clients
     */
    private function client(array $row, int $line, Collection $teams, array &$clients): Team
    {
        $name = trim($this->value($row, 'client'));

        if ($name === '') {
            throw ImportException::missingValue($line, 'client');
        }

        $team = $teams->first(fn (Team $team) => BusinessName::matches($team->name, $name));

        if ($team === null) {
            $team = Team::create([
                'name' => $name,
                'currency' => Currency::tryFrom(strtoupper(trim($this->value($row, 'client_currency')))) ?? Currency::Base,
            ]);

            $teams->push($team);
            $clients[] = $name;
        }

        $this->fillContactDetails($team, $row);

        return $team;
    }

    /**
     * Put the contact details on a client, without overwriting what is there.
     *
     * @param  array<string, string>  $row
     */
    private function fillContactDetails(Team $team, array $row): void
    {
        $details = array_filter([
            'billing_email' => $this->text($this->value($row, 'client_email')),
            'address' => $this->text($this->value($row, 'client_address')),
            'company_number' => $this->text($this->value($row, 'client_company_number')),
            'vat_number' => $this->text($this->value($row, 'client_vat_number')),
            'payment_terms_days' => $this->text($this->value($row, 'client_terms_days')),
        ], fn (?string $value) => $value !== null);

        $missing = array_filter(
            $details,
            fn (string $value, string $field) => blank($team->{$field}),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing !== []) {
            $team->update($missing);
        }
    }

    /**
     * Build one invoice from a row.
     *
     * @param  array<string, string>  $row
     */
    private function invoice(array $row, Team $team, string $number, int $line): Invoice
    {
        $status = InvoiceStatus::tryFrom(strtolower(trim($this->value($row, 'status')))) ?? InvoiceStatus::Sent;
        $record = strtolower(trim($this->value($row, 'type'))) === 'record';
        $issued = $this->date($this->value($row, 'issued_on'), $line, 'issued_on');
        $due = $this->text($this->value($row, 'due_on'));
        $paid = $this->text($this->value($row, 'paid_on'));

        return new Invoice([
            'number' => $number,
            'team_id' => $team->id,
            'type' => InvoiceType::AdHoc,
            'note' => $this->text($this->value($row, 'note')),
            'amount' => $this->money($this->value($row, 'amount')),
            'paid_to_date' => $this->money($this->value($row, 'paid_to_date')),
            'discount' => $this->money($this->value($row, 'discount')),
            'po_number' => $this->text($this->value($row, 'po_number')),
            'external_reference' => $this->text($this->value($row, 'reference')),
            'vat_rate' => (float) ($this->text($this->value($row, 'vat_rate')) ?? 0),
            'currency' => $team->currency,
            'issued_on' => $issued,
            'due_on' => $due === null
                ? $issued->copy()->addDays($team->effectivePaymentTerms($this->settings))
                : $this->date($due, $line, 'due_on'),
            'status' => $status,
            'record_only' => $record,
            'paid_at' => $paid === null || $status !== InvoiceStatus::Paid
                ? null
                : $this->date($paid, $line, 'paid_on'),
            // History is brought over for the record, not to restart
            // collections: an invoice from 2025 must not fire a final notice at
            // a client the moment somebody sets a billing address on them.
            'reminders_paused_at' => $status->isOutstanding() && ! $record ? now() : null,
        ]);
    }

    /**
     * Build one standing arrangement from a row, unless the client already has
     * the same one.
     *
     * @param  array<string, string>  $row
     */
    private function schedule(array $row, Team $team, int $line): ?RecurringInvoice
    {
        $note = $this->text($this->value($row, 'note')) ?? 'Monthly';

        if ($team->recurringInvoices()->where('note', $note)->exists()) {
            return null;
        }

        $starts = $this->text($this->value($row, 'starts_on'));
        $ends = $this->text($this->value($row, 'ends_on'));

        return $team->recurringInvoices()->create([
            'type' => InvoiceType::AdHoc,
            'note' => $note,
            'amount' => $this->money($this->value($row, 'amount')),
            'discount' => $this->money($this->value($row, 'discount')),
            'po_number' => $this->text($this->value($row, 'po_number')),
            'day_of_month' => max(1, min(31, (int) $this->value($row, 'day_of_month'))),
            'record_only' => strtolower(trim($this->value($row, 'type'))) === 'record',
            'starts_on' => $starts === null ? now()->startOfMonth() : $this->date($starts, $line, 'starts_on'),
            'ends_on' => $ends === null ? null : $this->date($ends, $line, 'ends_on'),
            'is_active' => true,
        ]);
    }

    /**
     * Read a column the file may not carry at all.
     *
     * @param  array<string, string>  $row
     */
    private function value(array $row, string $column): string
    {
        return (string) ($row[$column] ?? '');
    }

    /**
     * Parse a date, saying which row is wrong rather than throwing a parser
     * error at whoever uploaded the file.
     */
    private function date(string $value, int $line, string $column): Carbon
    {
        try {
            return Carbon::parse(trim($value));
        } catch (Throwable) {
            throw ImportException::badDate($line, $column, $value);
        }
    }

    /**
     * Parse a money column, which may carry thousands separators.
     */
    private function money(string $value): float
    {
        return (float) str_replace(',', '', trim($value));
    }

    /**
     * Get a trimmed value, or null when the column was empty or absent.
     */
    private function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
