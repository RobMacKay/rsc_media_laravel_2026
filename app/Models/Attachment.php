<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int|null $uploaded_by
 * @property string $name
 * @property string|null $path
 * @property string $kind
 * @property int $size
 * @property bool $shared_with_client
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $attachable
 * @property-read User|null $uploader
 */
#[Fillable(['uploaded_by', 'name', 'path', 'kind', 'size', 'shared_with_client'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /**
     * Get the ticket or project this file hangs off.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the person who uploaded the file.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the "412 KB · from Kirsty" style line shown beside the file name.
     */
    public function metaLabel(): string
    {
        $parts = [Number::fileSize($this->size)];

        $parts[] = $this->shared_with_client
            ? ($this->uploader?->name ? 'from '.str($this->uploader->name)->before(' ') : 'uploaded '.$this->created_at?->format('j M'))
            : 'private';

        return implode(' · ', $parts);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shared_with_client' => 'boolean',
        ];
    }
}
