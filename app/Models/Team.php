<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\Currency;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property int|null $plan_id
 * @property string|null $requested_plan
 * @property string|null $billing_email
 * @property Currency $currency
 * @property string|null $purchase_order_ref
 * @property int|null $hour_rate
 * @property int|null $day_rate
 * @property float|null $support_hours
 * @property int|null $payment_terms_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Plan|null $plan
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Proposal> $proposals
 * @property-read Collection<int, Ticket> $tickets
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, ProjectUpdate> $updates
 */
#[Fillable([
    'name', 'slug', 'is_personal', 'plan_id', 'requested_plan', 'billing_email',
    'purchase_order_ref', 'currency', 'hour_rate', 'day_rate', 'support_hours',
    'payment_terms_days',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * The model's default values, so a client built in memory still bills in
     * a currency rather than in nothing at all.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'GBP',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role', 'access', 'job_title'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the support plan this client is on, if any.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get this client's projects, newest first.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->latest();
    }

    /**
     * Get this client's proposals, newest first.
     *
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class)->latest();
    }

    /**
     * Get this client's tickets, newest first.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->latest();
    }

    /**
     * Get this client's invoices, newest first.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issued_on');
    }

    /**
     * Get the updates posted to this client's portal, newest first.
     *
     * @return HasMany<ProjectUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class)->latest('published_at');
    }

    /**
     * Format an amount in this client's currency.
     */
    public function money(float $amount, int $decimals = 0): string
    {
        return $this->currency->format($amount, $decimals);
    }

    /**
     * Get the hourly rate that applies to this client, falling back to the studio default.
     */
    public function effectiveHourRate(StudioSetting $settings): int
    {
        return $this->hour_rate ?? $settings->hour_rate;
    }

    /**
     * Get the day rate that applies to this client, falling back to the studio default.
     */
    public function effectiveDayRate(StudioSetting $settings): int
    {
        return $this->day_rate ?? $settings->day_rate;
    }

    /**
     * Get the payment terms that apply to this client, falling back to the studio default.
     */
    public function effectivePaymentTerms(StudioSetting $settings): int
    {
        return $this->payment_terms_days ?? $settings->payment_terms_days;
    }

    /**
     * Get the monthly support-hour allowance, taken from the override or the plan.
     */
    public function monthlySupportHours(): float
    {
        if ($this->support_hours !== null) {
            return (float) $this->support_hours;
        }

        return $this->plan_id === null ? 0.0 : (float) $this->plan->hours_per_month;
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'currency' => Currency::class,
            'support_hours' => 'float',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
