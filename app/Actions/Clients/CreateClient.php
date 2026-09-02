<?php

namespace App\Actions\Clients;

use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SetYourPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Open a client business and its first contact, the way the studio does it
 * when someone comes on board without signing up themselves.
 */
class CreateClient
{
    /**
     * Create the business, its first person, and email them to set a password.
     */
    public function handle(
        string $business,
        string $contactName,
        string $contactEmail,
        ?string $jobTitle = null,
        ?User $createdBy = null,
    ): Team {
        [$team, $user] = DB::transaction(function () use ($business, $contactName, $contactEmail, $jobTitle) {
            $team = Team::create(['name' => $business]);

            $user = User::create([
                'name' => $contactName,
                'email' => $contactEmail,
                // Never used: they set their own from the welcome link, and
                // must_set_password is what lets them.
                'password' => Str::password(32),
                'must_set_password' => true,
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
                'access' => ClientAccess::Full,
                'job_title' => $jobTitle,
            ]);

            $user->switchTeam($team);

            return [$team, $user];
        });

        $user->notify(new SetYourPassword($createdBy?->name));

        return $team;
    }
}
