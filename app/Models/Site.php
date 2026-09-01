<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Carbon\CarbonInterface;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A website the studio watches on a client's behalf.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $url
 * @property string $host
 * @property bool $is_active
 * @property SiteStatus $status
 * @property int|null $http_status
 * @property int|null $response_ms
 * @property bool|null $ssl_valid
 * @property CarbonInterface|null $ssl_expires_at
 * @property string|null $ssl_issuer
 * @property string|null $last_error
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $last_up_at
 * @property CarbonInterface|null $last_down_at
 * @property int $consecutive_failures
 * @property CarbonInterface|null $down_notified_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, SiteCheck> $checks
 */
#[Fillable([
    'team_id', 'name', 'url', 'host', 'is_active', 'status', 'http_status',
    'response_ms', 'ssl_valid', 'ssl_expires_at', 'ssl_issuer', 'last_error',
    'last_checked_at', 'last_up_at', 'last_down_at', 'consecutive_failures',
    'down_notified_at',
])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * How close to expiry a certificate has to be before we say so.
     */
    public const SSL_WARNING_DAYS = 21;

    /**
     * Get the client this site belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the check history, newest first.
     *
     * @return HasMany<SiteCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(SiteCheck::class)->latest('checked_at');
    }

    /**
     * Get the days left on the certificate, or null when there is nothing to say.
     */
    public function sslDaysLeft(): ?int
    {
        return $this->ssl_expires_at === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->ssl_expires_at->startOfDay(), false);
    }

    /**
     * Determine whether the certificate is close enough to expiry to mention.
     */
    public function sslExpiringSoon(): bool
    {
        $days = $this->sslDaysLeft();

        return $days !== null && $days <= self::SSL_WARNING_DAYS;
    }

    /**
     * Get the line describing the certificate, in plain words.
     */
    public function sslLabel(): string
    {
        if ($this->ssl_valid === null) {
            return __('Not checked yet');
        }

        if (! $this->ssl_valid) {
            return __('No valid certificate');
        }

        $days = $this->sslDaysLeft();

        if ($days === null) {
            return __('Valid');
        }

        return $days < 0
            ? __('Expired :days days ago', ['days' => abs($days)])
            : trans_choice('{0}Expires today|{1}Expires tomorrow|[2,*]Expires in :count days', $days, ['count' => $days]);
    }

    /**
     * Get the share of checks in the last month that found the site up.
     */
    public function uptimePercent(): ?float
    {
        $checks = $this->checks()->where('checked_at', '>=', now()->subDays(30));

        $total = (clone $checks)->count();

        if ($total === 0) {
            return null;
        }

        return round((clone $checks)->where('status', SiteStatus::Up)->count() / $total * 100, 2);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => SiteStatus::class,
            'ssl_valid' => 'boolean',
            'ssl_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_up_at' => 'datetime',
            'last_down_at' => 'datetime',
            'down_notified_at' => 'datetime',
        ];
    }
}
