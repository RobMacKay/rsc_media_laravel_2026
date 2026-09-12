<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something about an uploaded export is wrong in a way the person who uploaded
 * it can fix.
 *
 * Carries the field it belongs against, so the import screen can put the
 * message under the right dropzone rather than showing a failure with no clue
 * which of the two files caused it.
 */
class ImportException extends RuntimeException
{
    public function __construct(string $message, private string $field = 'invoices')
    {
        parent::__construct($message);
    }

    /**
     * The upload this is a complaint about.
     */
    public function field(): string
    {
        return $this->field;
    }

    /**
     * The file could not be read at all.
     */
    public static function unreadable(string $path, string $field = 'invoices'): self
    {
        return new self("Cannot read [{$path}].", $field);
    }

    /**
     * The file has no rows in it at all.
     */
    public static function empty(string $field = 'invoices'): self
    {
        return new self('That file is empty. Export the report from Invoice Ninja again.', $field);
    }

    /**
     * The file parsed, but it is not the report we were asked for.
     *
     * Names what is missing and what was actually there, because "undefined
     * array key" tells whoever uploaded it nothing about which of the dozen
     * Invoice Ninja reports they picked.
     *
     * @param  list<string>  $missing
     * @param  list<string>  $columns
     */
    public static function wrongReport(array $missing, array $columns, string $field = 'invoices'): self
    {
        $looksLikePayments = in_array('Payment Date', $columns, true);

        return new self(
            'That does not look like the Invoice report. It is missing '
            .self::list($missing).'. '
            .($looksLikePayments
                ? 'It looks like the Payment report — that one goes in the payments box below.'
                : 'In Invoice Ninja the report type has to be "Invoice".')
            .' Columns found: '.implode(', ', $columns).'.',
            $field,
        );
    }

    /**
     * A row carries something where a date should be.
     */
    public static function badDate(int $line, string $column, string $value, string $field = 'invoices'): self
    {
        return new self(
            "Row {$line} has \"{$value}\" in the {$column} column, which is not a date. "
            .'Fix that row in the export and upload it again.',
            $field,
        );
    }

    /**
     * Join names into a readable list.
     *
     * @param  list<string>  $names
     */
    private static function list(array $names): string
    {
        $quoted = array_map(fn (string $name) => '"'.$name.'"', $names);

        return count($quoted) === 1
            ? $quoted[0]
            : implode(', ', array_slice($quoted, 0, -1)).' and '.end($quoted);
    }

    /**
     * The payments file has no payment dates in it, which almost always means
     * the Invoice report was exported instead of the Payment one.
     *
     * @param  list<string>  $columns
     */
    public static function noPaymentDates(array $columns): self
    {
        return new self(
            'That file has no payment dates in it. In Invoice Ninja the report type has to be '
            .'"Payment", not "Invoice" — the invoice report filtered to Paid carries the same '
            .'columns and no payment date at all ("Paid to Date" is an amount, not a date). '
            .'Columns found: '.implode(', ', $columns).'.',
            'payments',
        );
    }
}
