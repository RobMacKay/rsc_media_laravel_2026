<?php

namespace App\Models;

use App\Enums\ProjectPhase;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $title
 * @property string|null $summary
 * @property ProjectPhase $phase
 * @property int $percent
 * @property string|null $milestone
 * @property Carbon|null $due_on
 * @property string|null $waiting_on_client
 * @property float $hours_used
 * @property float|null $hours_budgeted
 * @property string|null $value_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Ticket> $tickets
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable([
    'team_id', 'title', 'summary', 'phase', 'percent', 'milestone',
    'due_on', 'waiting_on_client', 'hours_used', 'hours_budgeted', 'value_label',
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
     * Get the tickets raised against this project.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
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
            'hours_used' => 'float',
            'hours_budgeted' => 'float',
        ];
    }
}
