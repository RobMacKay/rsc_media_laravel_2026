<?php

namespace App\Support;

use App\Exceptions\ImportException;
use Illuminate\Support\Str;

/**
 * A CSV handed to the application, read defensively.
 *
 * A spreadsheet that has been opened in Excel comes back with a byte order mark
 * and whatever separator the machine's locale prefers, and the file on somebody's
 * desk is rarely the one they meant. So nothing here trusts its shape: the
 * header is checked before a single row is used, and a file missing what it
 * needs says so, naming what it wanted and what it actually found.
 */
class CsvReport
{
    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $columns
     */
    private function __construct(
        private array $rows,
        private array $columns,
        private string $field,
    ) {}

    /**
     * Read a report off disk.
     *
     * The field is the upload it came from, so anything thrown lands under the
     * right control rather than as a failure with no clue which file was wrong.
     */
    public static function read(string $path, string $field = 'file'): self
    {
        if (! is_readable($path)) {
            throw ImportException::unreadable($path, $field);
        }

        $separator = self::separator($path, $field);

        $handle = fopen($path, 'r');

        // is_readable() a moment ago is not a promise the open succeeds: the
        // file can go, or the process can run out of handles, in between.
        if ($handle === false) {
            throw ImportException::unreadable($path, $field);
        }

        $header = fgetcsv($handle, separator: $separator, escape: '');

        if ($header === false || $header === [null]) {
            fclose($handle);

            throw ImportException::empty($field);
        }

        // A spreadsheet that has been through Excel starts with a byte order
        // mark, which would otherwise make the first column name unmatchable
        // while looking identical in any error message.
        $columns = array_map(
            fn ($name) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) ?? ''),
            $header,
        );

        $rows = [];

        while (($line = fgetcsv($handle, separator: $separator, escape: '')) !== false) {
            // The export ends with a blank line, and a short row would
            // silently shift every value along by one.
            if ($line === [null] || count(array_filter($line, fn ($value) => (string) $value !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine(
                $columns,
                array_pad(array_slice($line, 0, count($columns)), count($columns), ''),
            );
        }

        fclose($handle);

        return new self($rows, $columns, $field);
    }

    /**
     * Work out which character separates the columns.
     *
     * Invoice Ninja writes commas, but a file that has been opened and saved
     * again in Excel can come back semicolon or tab separated depending on the
     * machine's locale, and that parses as one enormous column rather than
     * failing outright.
     */
    private static function separator(string $path, string $field): string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ImportException::unreadable($path, $field);
        }

        $first = (string) fgets($handle);
        fclose($handle);

        $counts = [
            ',' => substr_count($first, ','),
            ';' => substr_count($first, ';'),
            "\t" => substr_count($first, "\t"),
        ];

        arsort($counts);

        return $counts[','] > 0 ? ',' : (string) array_key_first($counts);
    }

    /**
     * Refuse the file unless it carries every column named.
     *
     * Checked up front rather than discovered on the first row, so uploading
     * the wrong one of Invoice Ninja's reports says what it wanted instead of
     * dying on an undefined array key.
     *
     * @param  list<string>  $required
     */
    public function require(string $report, array $required): self
    {
        if ($this->rows === []) {
            return $this;
        }

        $missing = array_values(array_diff($required, $this->columns));

        if ($missing !== []) {
            throw ImportException::wrongReport($report, $missing, $this->columns, $this->field);
        }

        return $this;
    }

    /**
     * Get the rows, keyed by column name.
     *
     * @return list<array<string, string>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Get the column names, in the order the file gave them.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Read a column that a given export may not carry at all.
     *
     * Only the columns passed to require() are insisted on; everything else
     * goes through here, so a narrower report still imports what it does have
     * rather than falling over on a column nobody needed.
     *
     * @param  array<string, string>  $row
     */
    public function value(array $row, string $column): string
    {
        return (string) ($row[$column] ?? '');
    }

    /**
     * Find the column with exactly one of the given names, case aside.
     *
     * Matched whole rather than loosely, so a column whose name merely
     * resembles the one wanted is never read in its place.
     *
     * @param  list<string>  $candidates
     */
    public function column(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($this->columns as $column) {
                if (Str::of($column)->lower()->squish()->toString() === $candidate) {
                    return $column;
                }
            }
        }

        return null;
    }

    /**
     * Read the first of the given columns that this report actually carries.
     *
     * Invoice Ninja names the same thing differently between reports and
     * versions, so a caller asks for the candidates it knows about rather than
     * betting on one spelling.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $candidates
     */
    public function firstOf(array $row, array $candidates): string
    {
        $column = $this->column($candidates);

        return $column === null ? '' : $this->value($row, $column);
    }
}
