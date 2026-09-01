<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * Sign-up either joins an existing business via the invite code from the
     * welcome email, or opens a brand new business account from the form.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'business' => ['required_without:invitation', 'nullable', 'string', 'max:255'],
            'invitation' => ['nullable', 'string'],
            'password' => $this->passwordRules(),
        ])->validate();

        $invitation = $this->pendingInvitation($input);

        return DB::transaction(function () use ($input, $invitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($invitation) {
                $this->joinInvitedTeam($user, $invitation);

                return $user;
            }

            $this->createTeam->handle($user, $input['business']);

            return $user;
        });
    }

    /**
     * Resolve the pending invitation named by the invite code, if there is one.
     *
     * @param  array<string, string>  $input
     */
    private function pendingInvitation(array $input): ?TeamInvitation
    {
        $code = $input['invitation'] ?? null;

        if (! is_string($code) || $code === '') {
            return null;
        }

        $invitation = TeamInvitation::query()->with('team')->where('code', $code)->first();

        if (! $invitation || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('That invite code has expired or has already been used.'),
            ]);
        }

        return $invitation;
    }

    /**
     * Add the new user to the business that invited them.
     */
    private function joinInvitedTeam(User $user, TeamInvitation $invitation): void
    {
        $invitation->team->memberships()->create([
            'user_id' => $user->id,
            'role' => TeamRole::Member,
            // The access the inviter picked, falling back for invitations that
            // predate the field.
            'access' => $invitation->access ?? ClientAccess::Tickets,
        ]);

        $invitation->update(['accepted_at' => now()]);

        $user->switchTeam($invitation->team);
    }
}
