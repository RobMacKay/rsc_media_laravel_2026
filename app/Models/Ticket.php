<?php

namespace App\Models;

use App\Contracts\HasAttachments;
use App\Enums\BillingMode;
use App\Enums\QuoteResponse;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string $reference
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $reported_by
 * @property string $title
 * @property string $description
 * @property string|null $system
 * @property string|null $page_url
 * @property TicketType $type
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property Carbon|null $target_on
 * @property float|null $quoted_hours
 * @property int|null $quoted_rate
 * @property BillingMode $billing_mode
 * @property Carbon|null $quote_sent_at
 * @property QuoteResponse|null $quote_response
 * @property Carbon|null $quote_responded_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 * @property-read User|null $reporter
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Invoice|null $invoice
 * @property-read Collection<int, TicketComment> $comments
 * @property-read Collection<int, TicketRead> $reads
 */
#[Fillable([
    'reference', 'team_id', 'project_id', 'reported_by', 'title', 'description',
    'system', 'page_url', 'type', 'priority', 'status', 'target_on',
    'quoted_hours', 'quoted_rate', 'billing_mode', 'quote_sent_at',
    'quote_response', 'quote_responded_at', 'resolved_at',
])]
class Ticket extends Model implements HasAttachments
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * Allocate the next sequential ticket reference, e.g. RSC-1049.
     */
    public static function nextReference(): string
    {
        $last = (int) str(static::query()->max('reference') ?? 'RSC-1000')->afterLast('-')->toString();

        return 'RSC-'.max($last + 1, 1001);
    }

    /**
     * Get the client this ticket belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the project this ticket was raised against.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the person who raised the ticket.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Get the conversation on this ticket, oldest first.
     *
     * @return HasMany<TicketComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->oldest();
    }

    /**
     * Get the invoice raised for this ticket, if it has been billed.
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Get the files attached to this ticket.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get when each person last opened this ticket.
     *
     * @return HasMany<TicketRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(TicketRead::class);
    }

    /**
     * Note that this person has now seen the ticket as it stands.
     */
    public function markReadBy(User $user): void
    {
        $this->reads()->updateOrCreate(
            ['user_id' => $user->id],
            ['last_read_at' => now()],
        );
    }

    /**
     * Determine whether anything has moved on this ticket since this person
     * last opened it.
     *
     * A ticket nobody has opened counts as an update: for the studio that is a
     * new ticket, and for a client it is one raised by a colleague.
     *
     * Relies on `reads` being loaded for the one person we are asking about,
     * which `scopeWithReadsFor` does, so a list does not query per row.
     */
    public function hasUpdateFor(User $user): bool
    {
        $read = $this->reads->firstWhere('user_id', $user->id);

        if (! $read) {
            return true;
        }

        return $this->updated_at?->greaterThan($read->last_read_at) ?? false;
    }

    /**
     * Eager load just this person's read marks.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function withReadsFor(Builder $query, User $user): void
    {
        $query->with(['reads' => fn ($reads) => $reads->where('user_id', $user->id)]);
    }

    /**
     * Get the client business this record belongs to.
     */
    public function teamId(): int
    {
        return $this->team_id;
    }

    /**
     * Scope to tickets that still need work, most recently touched first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->where('status', '!=', TicketStatus::Resolved)->latest('updated_at');
    }

    /**
     * Get the value of the quote attached to this ticket, excluding VAT.
     */
    public function quoteTotal(): float
    {
        return (float) ($this->quoted_hours ?? 0) * (float) ($this->quoted_rate ?? 0);
    }

    /**
     * Determine whether a quote has been sent that the client has not answered yet.
     */
    public function hasQuoteAwaitingResponse(): bool
    {
        return $this->quote_sent_at !== null
            && $this->quote_response === null
            && $this->billing_mode === BillingMode::Chargeable;
    }

    /**
     * Scope to tickets with a quote the client has not answered yet, matching
     * hasQuoteAwaitingResponse() so a count and a row badge cannot disagree.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function awaitingQuoteResponse(Builder $query): void
    {
        $query->whereNotNull('quote_sent_at')
            ->whereNull('quote_response')
            ->where('billing_mode', BillingMode::Chargeable);
    }

    /**
     * Determine whether this ticket is chargeable work the client has agreed
     * to, and so is waiting to be invoiced.
     *
     * Support-hours and no-charge tickets never produce an invoice: the first
     * comes off the monthly allowance, the second is logged for the record.
     */
    public function isReadyToInvoice(): bool
    {
        return $this->billing_mode === BillingMode::Chargeable
            && $this->quote_response === QuoteResponse::Approved
            && $this->quoteTotal() > 0
            && $this->invoice === null;
    }

    /**
     * Get the "Updated 2 hours ago" style line the ticket lists show.
     */
    public function updatedLabel(): string
    {
        return $this->status === TicketStatus::Resolved && $this->resolved_at
            ? 'Closed '.$this->resolved_at->format('j F')
            : 'Updated '.$this->updated_at->diffForHumans();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TicketType::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'billing_mode' => BillingMode::class,
            'target_on' => 'date',
            'quoted_hours' => 'float',
            'quote_sent_at' => 'datetime',
            'quote_response' => QuoteResponse::class,
            'quote_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
