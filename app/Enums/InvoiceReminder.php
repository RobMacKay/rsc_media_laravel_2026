<?php

namespace App\Enums;

/**
 * The reminder stages an unpaid invoice passes through.
 *
 * Each one sends at most once, and there is deliberately nothing after
 * FinalNotice: past a fortnight a chase should come from a person, not from
 * software that has no idea what conversation is already happening.
 */
enum InvoiceReminder: string
{
    case DueSoon = 'due_soon';
    case JustOverdue = 'just_overdue';
    case Chasing = 'chasing';
    case FinalNotice = 'final_notice';

    /**
     * Get how many days after the due date this stage goes out.
     *
     * Negative means before, which is the point of the first one: a nudge
     * ahead of time prevents more lateness than any amount of chasing after.
     */
    public function daysFromDue(): int
    {
        return match ($this) {
            self::DueSoon => -3,
            self::JustOverdue => 1,
            self::Chasing => 7,
            self::FinalNotice => 14,
        };
    }

    /**
     * Get the subject line for this stage.
     */
    public function subject(string $number): string
    {
        return match ($this) {
            self::DueSoon => __('Invoice :number is due in a few days', ['number' => $number]),
            self::JustOverdue => __('Invoice :number was due yesterday', ['number' => $number]),
            self::Chasing => __('Invoice :number is a week overdue', ['number' => $number]),
            self::FinalNotice => __('Invoice :number is a fortnight overdue', ['number' => $number]),
        };
    }

    /**
     * Get the opening line, which sets the tone for the stage.
     */
    public function opening(): string
    {
        return match ($this) {
            self::DueSoon => __('A quick heads up before this one falls due.'),
            self::JustOverdue => __('This one slipped past its date yesterday — very possibly already in hand.'),
            self::Chasing => __('This one is now a week past its date.'),
            self::FinalNotice => __('This one is a fortnight past its date, so we wanted to flag it properly.'),
        };
    }

    /**
     * Get the closing line.
     */
    public function closing(): string
    {
        return match ($this) {
            self::DueSoon => __('Nothing to do if it is already scheduled.'),
            self::JustOverdue => __('If it has already gone out, ignore this and our apologies.'),
            self::Chasing => __('If something is holding it up, reply and tell us — we would rather know.'),
            self::FinalNotice => __('This is the last automatic reminder; we will pick it up with you directly from here.'),
        };
    }

    /**
     * Determine whether this stage is a chase rather than a courtesy note.
     */
    public function isChase(): bool
    {
        return $this !== self::DueSoon;
    }

    /**
     * Get the stage due for an invoice this many days past its due date, if any.
     *
     * Stages are checked newest first so an invoice that has been sitting for
     * a month does not walk back through the earlier ones.
     */
    public static function dueAfter(int $daysPastDue): ?self
    {
        foreach (array_reverse(self::cases()) as $stage) {
            if ($daysPastDue >= $stage->daysFromDue()) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Determine whether this stage comes after another one.
     */
    public function isAfter(?self $other): bool
    {
        return $other === null || $this->daysFromDue() > $other->daysFromDue();
    }
}
