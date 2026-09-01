<?php

namespace App\Models;

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
 * @property Carbon $issued_on
 * @property Carbon $due_on
 * @property InvoiceStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 * @property-read Ticket|null $ticket
 */
#[Fillable([
    'number', 'team_id', 'project_id', 'ticket_id', 'type', 'note', 'amount',
    'vat_rate', 'issued_on', 'due_on', 'status', 'paid_at',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

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
     */
    public function isOverdue(): bool
    {
        return $this->status->isOutstanding() && $this->due_on->isPast();
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
            'issued_on' => 'date',
            'due_on' => 'date',
            'paid_at' => 'datetime',
        ];
    }
}
