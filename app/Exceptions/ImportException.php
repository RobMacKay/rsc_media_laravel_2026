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
