<?php

namespace App\Models;

use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An enquiry sent through the contact form on the public site.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string $topic
 * @property string $message
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'company', 'topic', 'message', 'handled_at'])]
class Enquiry extends Model
{
    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    /**
     * The subjects an enquiry can be filed under.
     *
     * @var array<string, string>
     */
    public const TOPICS = [
        'app' => 'New application',
        'existing' => 'Existing system',
        'site' => 'Website',
        'advice' => 'Just advice',
    ];

    /**
     * Get the display label for this enquiry's topic.
     */
    public function topicLabel(): string
    {
        return self::TOPICS[$this->topic] ?? $this->topic;
    }

    /**
     * Determine whether this one has been dealt with.
     */
    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }

    /**
     * Note that this one has been dealt with, or put it back if it has not.
     */
    public function setHandled(bool $handled): void
    {
        $this->update(['handled_at' => $handled ? now() : null]);
    }

    /**
     * Scope to enquiries nobody has dealt with yet.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function unhandled(Builder $query): void
    {
        $query->whereNull('handled_at');
    }

    /**
     * Scope to enquiries that have been dealt with.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function handled(Builder $query): void
    {
        $query->whereNotNull('handled_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }
}
