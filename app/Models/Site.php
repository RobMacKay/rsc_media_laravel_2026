<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Support\Sites\SshResult;
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
 * @property bool $ssh_enabled
 * @property int $ssh_port
 * @property SiteStatus $status
 * @property int|null $http_status
 * @property int|null $response_ms
 * @property bool|null $ssl_valid
 * @property CarbonInterface|null $ssl_expires_at
 * @property string|null $ssl_issuer
 * @property bool|null $ssh_ok
 * @property string|null $ssh_banner
 * @property string|null $ssh_error
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
    'team_id', 'name', 'url', 'host', 'is_active', 'ssh_enabled', 'ssh_port',
    'status', 'http_status', 'response_ms', 'ssl_valid', 'ssl_expires_at',
    'ssl_issuer', 'ssh_ok', 'ssh_banner', 'ssh_error', 'last_error',
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
     * The model's default values.
     *
     * The database has the same defaults, but a freshly created model does not
     * read them back, so a site checked in the same request as it was made
     * would otherwise have no port to knock on.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'ssh_enabled' => false,
        'ssh_port' => 22,
        'status' => 'unknown',
        'consecutive_failures' => 0,
    ];

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
     * Get the line describing SSH, in plain words.
     */
    public function sshLabel(): string
    {
        if (! $this->ssh_enabled) {
            return __('Not watched');
        }

        if ($this->ssh_ok === null) {
            return __('Not checked yet');
        }

        if (! $this->ssh_ok) {
            return __('Not answering');
        }

        return $this->sshServerVersion() ?? __('Answering');
    }

    /**
     * Get the SSH server version out of the stored greeting.
     */
    public function sshServerVersion(): ?string
    {
        return $this->ssh_banner === null
            ? null
            : (new SshResult(reachable: true, banner: $this->ssh_banner))->serverVersion();
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
            'ssh_enabled' => 'boolean',
            'ssh_ok' => 'boolean',
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
