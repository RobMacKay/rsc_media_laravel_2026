<?php

namespace App\Actions\Clients;

use App\Exceptions\ImportException;
use App\Models\Team;
use App\Support\BusinessName;
use App\Support\CsvReport;
use Illuminate\Support\Facades\DB;

/**
 * Fill in the contact details of clients already on the books, from Invoice
 * Ninja's Clients report.
 *
 * The invoice import brings businesses across with a name and a currency and
 * nothing else, because the invoice export carries nothing else. Without an
 * address there is no way to invite a client into the portal, and a re-issued
 * PDF goes out with no client address on it.
 *
 * Deliberately does not open businesses of its own: a client with no invoice
 * history is not something the studio needs a record of, and the invoice import
 * is the one place a client arrives from a file.
 */
class ImportClients
{
    /**
     * The columns the Clients export cannot be read without.
     */
    private const Required = ['Client Name'];

    /**
     * Where the contact address might be called, newest spelling first.
     */
    private const EmailColumns = [
        'client contact email', 'contact email', 'client email', 'email',
    ];

    /**
     * Where the first line of the postal address might be called.
     */
    private const StreetColumns = ['client street', 'client address1', 'address1', 'street'];

    /**
     * Import contact details, matching on the business name.
     *
     * Only fills a field that is currently blank. The studio may have corrected
     * an address by hand since the export was taken, and an import must not
     * quietly put the stale one back.
     *
     * @return array{
     *     filled: list<array{client: string, email: string|null}>,
     *     kept: list<string>,
     *     unmatched: list<string>,
     * }
     */
    public function handle(string $path, bool $dryRun = false): array
    {
        $report = CsvReport::read($path, 'clients')->require('Clients', self::Required);

        // "Client Name" alone is far too weak a signal: the invoice and
        // payment reports both carry it, so requiring only that would accept
        // either of them and then quietly fill nothing in.
        if ($report->rows() !== []
            && $report->column(self::EmailColumns) === null
            && $report->column(self::StreetColumns) === null) {
            throw ImportException::wrongReport(
                'Clients',
                ['Client Contact Email'],
                $report->columns(),
                'clients',
            );
        }

        $teams = Team::withTrashed()->get();

        $filled = [];
        $kept = [];
        $unmatched = [];

        DB::beginTransaction();

        try {
            foreach ($report->rows() as $row) {
                $name = trim($row['Client Name']);

                if ($name === '') {
                    continue;
                }

                $team = $teams->first(fn (Team $team) => BusinessName::matches($team->name, $name));

                if ($team === null) {
                    $unmatched[] = $name;

                    continue;
                }

                $details = array_filter([
                    'billing_email' => $this->email($report, $row),
                    'address' => $this->address($report, $row),
                    'company_number' => $this->text($report->firstOf($row, ['client id number', 'id number'])),
                    'vat_number' => $this->text($report->firstOf($row, ['client vat number', 'vat number'])),
                ], fn (?string $value) => $value !== null);

                // Anything the studio has already filled in wins.
                $missing = array_filter(
                    $details,
                    fn (string $value, string $field) => blank($team->{$field}),
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($missing === []) {
                    $kept[] = $team->name;

                    continue;
                }

                $team->update($missing);

                $filled[] = [
                    'client' => $team->name,
                    'email' => $missing['billing_email'] ?? null,
                ];
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['filled' => $filled, 'kept' => $kept, 'unmatched' => $unmatched];
    }

    /**
     * Get the contact address, if the export carries a usable one.
     *
     * @param  array<string, string>  $row
     */
    private function email(CsvReport $report, array $row): ?string
    {
        $email = $this->text($report->firstOf($row, self::EmailColumns));

        // Invoice Ninja will happily export a blank or malformed contact, and
        // an invitation sent to one of those bounces silently.
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Build the postal address from whichever parts the export carries.
     *
     * @param  array<string, string>  $row
     */
    private function address(CsvReport $report, array $row): ?string
    {
        $lines = array_filter([
            $this->text($report->firstOf($row, self::StreetColumns)),
            $this->text($report->firstOf($row, ['client address2', 'address2'])),
            $this->text($report->firstOf($row, ['client city', 'city'])),
            $this->text($report->firstOf($row, ['client state', 'state', 'county'])),
            $this->text($report->firstOf($row, ['client postal code', 'postal code', 'postcode'])),
        ]);

        return $lines === [] ? null : implode("\n", $lines);
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
