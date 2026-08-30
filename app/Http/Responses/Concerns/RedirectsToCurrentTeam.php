<?php

namespace App\Http\Responses\Concerns;

use Illuminate\Http\Request;

trait RedirectsToCurrentTeam
{
    /**
     * Get the portal landing path for the authenticated user.
     *
     * Studio administrators land in the admin queue; everyone else lands in the
     * client area for whichever business they are currently signed in against.
     */
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $user = $request->user();

        abort_if(! $user, 403);

        if (! $user->currentTeam && $team = $user->personalTeam()) {
            $user->switchTeam($team);
        }

        return $user->is_admin
            ? route('admin.queue', absolute: false)
            : route('client.dashboard', absolute: false);
    }
}
