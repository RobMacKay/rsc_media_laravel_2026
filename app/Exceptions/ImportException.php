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
    public function __construct(string $message, private string $field = 'file')
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
    public static function unreadable(string $path, string $field = 'file'): self
    {
        return new self("Cannot read [{$path}].", $field);
    }

    /**
     * The file has no rows in it at all.
     */
    public static function empty(string $field = 'file'): self
    {
        return new self('That file is empty. Export the report from Invoice Ninja again.', $field);
    }

    /**
     * The file parsed, but it is not the shape we were asked for.
     *
     * Names what is missing and what was actually there, because "undefined
     * array key" tells whoever uploaded it nothing about which of the files on
     * their desk they picked.
     *
     * @param  list<string>  $missing
     * @param  list<string>  $columns
     */
    public static function wrongReport(string $report, array $missing, array $columns, string $field = 'file'): self
    {
        return new self(
            "That does not look like the {$report} file. It is missing "
            .self::list($missing).'. Columns found: '.implode(', ', $columns).'.',
            $field,
        );
    }

    /**
     * A row says it is something the importer does not know how to read.
     */
    public static function badRowType(int $line, string $value, string $field = 'file'): self
    {
        return new self(
            "Row {$line} has a row_type of \"{$value}\". It has to be \"invoice\" or \"schedule\".",
            $field,
        );
    }

    /**
     * A row is missing something it cannot be read without.
     */
    public static function missingValue(int $line, string $column, string $field = 'file'): self
    {
        return new self("Row {$line} has no {$column}, which every row needs.", $field);
    }

    /**
     * A row carries something where a date should be.
     */
    public static function badDate(int $line, string $column, string $value, string $field = 'file'): self
    {
        return new self(
            "Row {$line} has \"{$value}\" in the {$column} column, which is not a date. "
            .'Fix that row and upload it again.',
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
}
