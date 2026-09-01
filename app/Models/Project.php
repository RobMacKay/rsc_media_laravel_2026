<?php

namespace App\Models;

use App\Enums\InvoiceType;
use App\Enums\ProjectPhase;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $reference
 * @property int $team_id
 * @property string $title
 * @property string|null $summary
 * @property ProjectPhase $phase
 * @property int $percent
 * @property string|null $milestone
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_on
 * @property string|null $waiting_on_client
 * @property float $hours_used
 * @property float|null $hours_budgeted
 * @property string|null $value_label
 * @property int|null $agreed_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Ticket> $tickets
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable([
    'reference', 'team_id', 'title', 'summary', 'phase', 'percent', 'milestone',
    'due_on', 'completed_on', 'waiting_on_client', 'hours_used', 'hours_budgeted',
    'value_label', 'agreed_value',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the client this project belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the proposal this project was signed off from, if it came from one.
     *
     * @return HasOne<Proposal, $this>
     */
    public function proposal(): HasOne
    {
        return $this->hasOne(Proposal::class);
    }

    /**
     * Get the tickets raised against this project.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get the invoices raised against this project.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the files attached to this project.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get what has been billed against the agreed price, excluding VAT.
     *
     * Only deposits and final invoices draw the contract down. Ad hoc work,
     * such as a chargeable ticket raised against this project, is extra to the
     * fixed price, and plan invoices are the separate monthly retainer —
     * counting either would quietly under-bill the balance.
     */
    public function contractInvoiced(): int
    {
        return (int) $this->invoices()
            ->whereIn('type', [InvoiceType::Deposit, InvoiceType::Final])
            ->sum('amount');
    }

    /**
     * Get what is still to be billed on the agreed price.
     *
     * Zero when there is no fixed total to bill against, such as a recurring
     * care plan, or when the project is already fully invoiced.
     */
    public function balanceToInvoice(): int
    {
        if ($this->agreed_value === null) {
            return 0;
        }

        return max($this->agreed_value - $this->contractInvoiced(), 0);
    }

    /**
     * Determine whether the work is finished and live.
     */
    public function isComplete(): bool
    {
        return $this->completed_on !== null || $this->percent >= 100;
    }

    /**
     * Get the hours summary the admin jobs board shows under the progress bar.
     */
    public function hoursLabel(): string
    {
        $used = rtrim(rtrim(number_format($this->hours_used, 1), '0'), '.');

        if ($this->hours_budgeted === null) {
            return $used.' hours logged';
        }

        return $used.' of '.rtrim(rtrim(number_format($this->hours_budgeted, 1), '0'), '.').' hours';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => ProjectPhase::class,
            'due_on' => 'date',
            'completed_on' => 'date',
            'hours_used' => 'float',
            'hours_budgeted' => 'float',
        ];
    }
}
