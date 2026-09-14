<?php

namespace App\Models;

use App\Contracts\HasAttachments;
use App\Enums\Currency;
use App\Enums\InvoiceReminder;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $ticket_id
 * @property int|null $recurring_invoice_id
 * @property InvoiceType $type
 * @property string|null $note
 * @property float $amount
 * @property float $paid_to_date
 * @property float $discount
 * @property string|null $po_number
 * @property string|null $external_reference
 * @property float $vat_rate
 * @property Currency $currency
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property InvoiceStatus $status
 * @property bool $record_only
 * @property Carbon|null $paid_at
 * @property InvoiceReminder|null $reminder_stage
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $reminders_paused_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 * @property-read Ticket|null $ticket
 * @property-read RecurringInvoice|null $recurringInvoice
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable([
    'number', 'team_id', 'project_id', 'ticket_id', 'recurring_invoice_id', 'type',
    'note', 'amount', 'paid_to_date', 'discount', 'po_number', 'external_reference',
    'vat_rate', 'currency', 'issued_on', 'due_on', 'status', 'record_only', 'paid_at',
    'reminder_stage', 'last_reminded_at', 'reminders_paused_at',
])]
class Invoice extends Model implements HasAttachments
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * The model's default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'GBP',
    ];

    /**
     * The prefix on the studio's own invoice numbers.
     */
    public const Prefix = 'RSC';

    /**
     * The prefix on a record of work somebody else billed.
     */
    public const RecordPrefix = 'REC';

    /**
     * The prefix on history imported from the studio's previous system.
     */
    public const ImportPrefix = 'IN';

    /**
     * Allocate the next sequential invoice number, e.g. RSC-0148.
     *
     * Each prefix keeps its own sequence, and the match is on the prefix
     * rather than the whole table: imported history and records of somebody
     * else's billing must never move the studio's own numbering along.
     */
    public static function nextNumber(string $prefix = self::Prefix): string
    {
        $last = (int) str(
            static::query()->where('number', 'like', $prefix.'-%')->max('number') ?? '0000'
        )->afterLast('-')->toString();

        return $prefix.'-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the client this invoice belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the project this invoice was raised against.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the ticket this invoice was raised for, if it came from one.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the schedule that raised this invoice, if one did.
     *
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * Get the files held against this invoice, such as the remittance advice
     * behind a record.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get the client business this record belongs to.
     */
    public function teamId(): int
    {
        return $this->team_id;
    }

    /**
     * Scope to invoices that have not been paid.
     *
     * A record is never outstanding: the studio did not raise it and is not
     * waiting on it, so it has no business in a list of money owed.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function outstanding(Builder $query): void
    {
        $query->where('status', '!=', InvoiceStatus::Paid)->where('record_only', false);
    }

    /**
     * Get the purchase order reference to show on this invoice.
     *
     * An invoice's own reference wins: a client with a standing PO number may
     * still raise a fresh one per job, which is what an agency putting work
     * through job by job does.
     */
    public function purchaseOrderRef(): ?string
    {
        return $this->po_number ?? $this->team->purchase_order_ref;
    }

    /**
     * Scope to the invoices the studio actually issued to the client.
     *
     * This is what the client portal shows. A record is the studio's own
     * bookkeeping for work an agency billed and paid by remittance — the
     * client on it never received an invoice from RSC Media, so showing them
     * one would be inventing a document.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function issued(Builder $query): void
    {
        $query->where('record_only', false);
    }

    /**
     * Get the amount before any discount was applied.
     */
    public function subtotal(): float
    {
        return $this->amount + $this->discount;
    }

    /**
     * Get what is still owed on this invoice.
     */
    public function balance(): float
    {
        return max($this->total() - $this->paid_to_date, 0);
    }

    /**
     * Get the VAT charged on this invoice.
     */
    public function vatAmount(): float
    {
        return $this->amount * $this->vat_rate / 100;
    }

    /**
     * Get the invoice total including VAT.
     */
    public function total(): float
    {
        return $this->amount + $this->vatAmount();
    }

    /**
     * Format an amount in the currency this invoice was raised in.
     */
    public function money(float $amount, int $decimals = 0): string
    {
        return $this->currency->format($amount, $decimals);
    }

    /**
     * Format an amount for a list, showing pence only when there are any.
     *
     * The studio's own invoices are round numbers and reading "£550.00" down a
     * column of them is noise; the imported history has pence on it and
     * dropping them would misstate what a client actually paid.
     */
    public function moneyLabel(float $amount): string
    {
        return $this->money($amount, $amount === (float) (int) $amount ? 0 : 2);
    }

    /**
     * Get the reference the client should quote when they pay, built from the
     * format the studio set in its settings.
     */
    public function paymentReference(StudioSetting $settings): string
    {
        return str_replace(
            '{invoice}',
            str($this->number)->afterLast('-')->toString(),
            $settings->reference_format,
        );
    }

    /**
     * Determine whether this invoice is past its due date and still unpaid.
     *
     * Derived rather than read off the status, so it is right the moment the
     * date passes rather than whenever the daily command last ran.
     */
    public function isOverdue(): bool
    {
        return ! $this->record_only
            && $this->status->isOutstanding()
            && $this->status->hasBeenSent()
            && $this->due_on->isPast();
    }

    /**
     * Get how many days past its due date this invoice is. Negative until then.
     */
    public function daysPastDue(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->due_on->startOfDay(), false) * -1;
    }

    /**
     * Get the reminder stage that is due to go out, if any.
     */
    public function reminderDue(): ?InvoiceReminder
    {
        // Nobody is chased over a record. The money came from an agency that
        // bills itself, and the client on it never asked us for an invoice.
        if ($this->record_only) {
            return null;
        }

        if (! $this->status->isOutstanding() || ! $this->status->hasBeenSent()) {
            return null;
        }

        if ($this->reminders_paused_at !== null) {
            return null;
        }

        $stage = InvoiceReminder::dueAfter($this->daysPastDue());

        return $stage?->isAfter($this->reminder_stage) ? $stage : null;
    }

    /**
     * Determine whether this invoice has run out of automatic reminders.
     */
    public function remindersExhausted(): bool
    {
        return ! $this->record_only
            && $this->status->isOutstanding()
            && $this->reminder_stage === InvoiceReminder::FinalNotice;
    }

    /**
     * Scope to invoices that are past their due date and still unpaid.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', '!=', InvoiceStatus::Paid)
            ->where('record_only', false)
            ->whereDate('due_on', '<', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'amount' => 'float',
            'paid_to_date' => 'float',
            'discount' => 'float',
            'vat_rate' => 'float',
            'record_only' => 'boolean',
            'currency' => Currency::class,
            'issued_on' => 'date',
            'due_on' => 'date',
            'paid_at' => 'datetime',
            'reminder_stage' => InvoiceReminder::class,
            'last_reminded_at' => 'datetime',
            'reminders_paused_at' => 'datetime',
        ];
    }
}
