<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Carbon\CarbonInterface;
use Database\Factories\SiteCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of one site: the row the downloadable log is built from.
 *
 * @property int $id
 * @property int $site_id
 * @property CarbonInterface $checked_at
 * @property SiteStatus $status
 * @property int|null $http_status
 * @property int|null $response_ms
 * @property bool|null $ssl_valid
 * @property CarbonInterface|null $ssl_expires_at
 * @property string|null $error
 * @property-read Site $site
 */
#[Fillable([
    'site_id', 'checked_at', 'status', 'http_status', 'response_ms',
    'ssl_valid', 'ssl_expires_at', 'error',
])]
class SiteCheck extends Model
{
    /** @use HasFactory<SiteCheckFactory> */
    use HasFactory;

    /**
     * Get the site this check belongs to.
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'status' => SiteStatus::class,
            'ssl_valid' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }
}
