<?php

namespace App\Models;

use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message on a ticket, either between the studio and the client or a note
 * the studio keeps to itself.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ticket $ticket
 * @property-read User|null $author
 */
#[Fillable(['user_id', 'body', 'is_internal'])]
class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory;

    /**
     * Get the ticket this comment belongs to.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the person who wrote the comment.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to the comments the client is allowed to read.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function sharedWithClient(Builder $query): void
    {
        $query->where('is_internal', false);
    }

    /**
     * Determine whether this comment was written by the studio.
     */
    public function fromStudio(): bool
    {
        return (bool) $this->author?->is_admin;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }
}
