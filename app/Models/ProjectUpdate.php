<?php

namespace App\Models;

use App\Enums\UpdateKind;
use Database\Factories\ProjectUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property UpdateKind $kind
 * @property string $tag
 * @property string $title
 * @property string $body
 * @property Carbon $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 */
#[Fillable(['team_id', 'project_id', 'kind', 'tag', 'title', 'body', 'published_at'])]
class ProjectUpdate extends Model
{
    /** @use HasFactory<ProjectUpdateFactory> */
    use HasFactory;

    /**
     * Get the client this update was posted to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the project this update relates to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => UpdateKind::class,
            'published_at' => 'datetime',
        ];
    }
}
