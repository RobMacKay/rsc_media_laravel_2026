<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\InvoiceReminder;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $ticket_id
 * @property InvoiceType $type
 * @property string|null $note
 * @property int $amount
 * @property float $vat_rate
 * @property Currency $currency
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property InvoiceStatus $status
 * @property Carbon|null $paid_at
 * @property InvoiceReminder|null $reminder_stage
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $reminders_paused_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 * @property-read Ticket|null $ticket
 */
#[Fillable([
    'number', 'team_id', 'project_id', 'ticket_id', 'type', 'note', 'amount',
    'vat_rate', 'currency', 'issued_on', 'due_on', 'status', 'paid_at',
    'reminder_stage', 'last_reminded_at', 'reminders_paused_at',
])]
class Invoice extends Model
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
     * Allocate the next sequential invoice number, e.g. RSC-0148.
     */
    public static function nextNumber(): string
    {
        $last = (int) str(static::query()->max('number') ?? 'RSC-0000')->afterLast('-')->toString();

        return 'RSC-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
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
     * Scope to invoices that have not been paid.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function outstanding(Builder $query): void
    {
        $query->where('status', '!=', InvoiceStatus::Paid);
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
        return $this->status->isOutstanding()
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
        return $this->status->isOutstanding()
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
        $query->where('status', '!=', InvoiceStatus::Paid)->whereDate('due_on', '<', now());
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
            'vat_rate' => 'float',
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
