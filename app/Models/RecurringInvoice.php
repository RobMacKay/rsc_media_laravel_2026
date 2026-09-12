<?php

namespace App\Models;

use App\Enums\InvoiceType;
use Carbon\CarbonInterface;
use Database\Factories\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A standing monthly arrangement with one client, such as discounted hosting
 * for a charity or a retainer that is not one of the published plans.
 *
 * Deliberately separate from Plan: a plan is a product the studio sells, with
 * a price and a billing strip in the portal. These are private arrangements at
 * whatever was agreed, on whatever day of the month was agreed.
 *
 * @property int $id
 * @property int $team_id
 * @property InvoiceType $type
 * @property string $note
 * @property float $amount
 * @property float $discount
 * @property string|null $po_number
 * @property int $day_of_month
 * @property bool $record_only
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Invoice> $invoices
 */
#[Fillable([
    'team_id', 'type', 'note', 'amount', 'discount', 'po_number',
    'day_of_month', 'record_only', 'starts_on', 'ends_on', 'is_active',
])]
class RecurringInvoice extends Model
{
    /** @use HasFactory<RecurringInvoiceFactory> */
    use HasFactory;

    /**
     * Get the client this arrangement is with.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the invoices this schedule has raised.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Scope to the schedules that are switched on and within their dates.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function running(Builder $query, ?CarbonInterface $on = null): void
    {
        $on ??= Date::now();

        $query->where('is_active', true)
            ->whereDate('starts_on', '<=', $on)
            ->where(fn (Builder $query) => $query
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $on));
    }

    /**
     * Get the date this schedule bills on in the given month.
     *
     * A schedule set to the 31st bills on the 30th of a thirty-day month and
     * the 28th of February, rather than skipping those months entirely.
     */
    public function billingDateIn(CarbonInterface $month): CarbonInterface
    {
        $start = $month->copy()->startOfMonth();

        return $start->setDay(min($this->day_of_month, $start->daysInMonth));
    }

    /**
     * Determine whether this schedule's billing day has come round in the
     * given month.
     */
    public function isDueBy(CarbonInterface $on): bool
    {
        return $this->billingDateIn($on)->startOfDay()->lessThanOrEqualTo($on->copy()->startOfDay());
    }

    /**
     * Get the note to put on the invoice for the given month, which spells out
     * the month so a year of them can be told apart.
     */
    public function noteFor(CarbonInterface $month): string
    {
        return $this->note.' — '.$month->format('F Y');
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
            'amount' => 'float',
            'discount' => 'float',
            'day_of_month' => 'integer',
            'record_only' => 'boolean',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
