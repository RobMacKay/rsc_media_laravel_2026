<?php

namespace App\Models;

use App\Contracts\HasAttachments;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

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
 * @property-read (Model&HasAttachments)|null $attachable
 * @property-read User|null $uploader
 */
#[Fillable(['uploaded_by', 'name', 'path', 'kind', 'size', 'shared_with_client'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /**
     * What a client may attach, and how much of it. Screenshots and paperwork,
     * per the design; nothing that could be served back as code.
     */
    public const CLIENT_MIMES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'];

    public const CLIENT_MAX_KB = 10 * 1024;

    /**
     * What the studio may attach. Wider, because it sends quotes and specs.
     */
    public const STUDIO_MIMES = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'txt', 'md', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
    ];

    public const STUDIO_MAX_KB = 25 * 1024;

    /**
     * Delete the file off disk whenever the row goes, so nothing is orphaned.
     */
    protected static function booted(): void
    {
        static::deleted(function (Attachment $attachment) {
            if ($attachment->path) {
                Storage::disk('local')->delete($attachment->path);
            }
        });
    }

    /**
     * Get the largest upload that will actually get through, in kilobytes.
     *
     * PHP refuses anything over upload_max_filesize or post_max_size before a
     * request reaches Laravel, so promising 25MB on a server configured for
     * 2MB would fail with nothing useful to show the person uploading. Take
     * the smallest of the three and tell the truth instead.
     */
    public static function maxUploadKb(int $preferredKb): int
    {
        $limits = [$preferredKb];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $kb = self::iniKb((string) ini_get($setting));

            if ($kb !== null && $kb > 0) {
                $limits[] = $kb;
            }
        }

        return min($limits);
    }

    /**
     * Read a php.ini shorthand size such as "8M" or "512K" as kilobytes.
     */
    private static function iniKb(string $value): ?int
    {
        if (! preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/i', $value, $matches)) {
            return null;
        }

        $size = (int) $matches[1];

        return match (mb_strtoupper($matches[2])) {
            'G' => $size * 1024 * 1024,
            'M' => $size * 1024,
            'K' => $size,
            default => intdiv($size, 1024),
        };
    }

    /**
     * Get the rules for a file being attached.
     *
     * @param  array<int, string>  $mimes
     * @return array<int, string>
     */
    public static function rules(array $mimes, int $maxKb, bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimes:'.implode(',', $mimes),
            'max:'.$maxKb,
        ];
    }

    /**
     * Get messages that name the file rather than the property behind it, so
     * nobody is told that "the reply field must be a file of type".
     *
     * @param  array<int, string>  $mimes
     * @return array<string, string>
     */
    public static function messages(string $field, array $mimes, int $maxKb): array
    {
        return [
            $field.'.mimes' => __('That file type is not supported. Attach a :types.', [
                'types' => Str::of(implode(', ', array_map(mb_strtoupper(...), $mimes)))->replaceLast(', ', ' or '),
            ]),
            $field.'.max' => __('That file is too big. The limit is :size.', [
                'size' => Number::fileSize($maxKb * 1024),
            ]),
            $field.'.file' => __('That does not look like a file.'),
            $field.'.uploaded' => __('That file could not be uploaded. It may be over the limit.'),
        ];
    }

    /**
     * Get the short kind badge shown beside the file, e.g. PDF or PNG.
     */
    public static function kindFor(string $name): string
    {
        $extension = Str::of($name)->afterLast('.')->upper()->toString();

        return $extension === '' || mb_strlen($extension) > 8 || ! str_contains($name, '.')
            ? 'FILE'
            : $extension;
    }

    /**
     * Determine whether this file may be handed to the given person.
     *
     * Studio staff see everything. A client sees files on their own business's
     * records, and only the ones the studio marked as shared.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! $this->shared_with_client) {
            return false;
        }

        return $this->attachable !== null
            && $this->attachable->teamId() === $user->current_team_id;
    }

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
