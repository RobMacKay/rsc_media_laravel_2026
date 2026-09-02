<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\ClientAccess;
use App\Enums\ContactPreference;
use App\Enums\NotificationTopic;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property ContactPreference $contact_preference
 * @property array<string, bool> $notification_preferences
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $email_verified_at
 * @property bool $is_admin
 * @property bool $must_set_password
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable([
    'name', 'email', 'phone', 'password', 'current_team_id', 'is_admin',
    'contact_preference', 'notification_preferences', 'onboarded_at', 'must_set_password',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The model's default values.
     *
     * MySQL will not take a default on a JSON column, so it lives here.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'contact_preference' => 'email',
    ];

    /**
     * Get the notification topics this person has opted into, falling back to
     * the defaults for an account that has not been through the wizard.
     *
     * @return array<string, bool>
     */
    public function notificationChoices(): array
    {
        return [...NotificationTopic::defaults(), ...($this->notification_preferences ?? [])];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'must_set_password' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'contact_preference' => ContactPreference::class,
            'notification_preferences' => 'array',
            'onboarded_at' => 'datetime',
        ];
    }

    /**
     * Determine whether this person has been through the welcome wizard.
     *
     * Skipping counts as done: the wizard says everything can be changed later
     * from settings, so it must not keep asking.
     */
    public function hasOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    /**
     * Determine whether this person wants an email about the given topic.
     */
    public function wantsEmailAbout(NotificationTopic $topic): bool
    {
        return (bool) ($this->notification_preferences[$topic->value] ?? $topic->onByDefault());
    }

    /**
     * Get the route name of the portal this user lands in after signing in.
     */
    public function portalRoute(): string
    {
        return $this->is_admin ? 'admin.queue' : 'client.dashboard';
    }

    /**
     * Get the access level this user has inside the given team, defaulting to their current one.
     */
    public function accessFor(?Team $team = null): ClientAccess
    {
        $team ??= $this->currentTeam;

        if (! $team) {
            return ClientAccess::View;
        }

        $access = $this->teamMemberships()
            ->where('team_id', $team->id)
            ->value('access');

        return $access instanceof ClientAccess ? $access : ClientAccess::View;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
