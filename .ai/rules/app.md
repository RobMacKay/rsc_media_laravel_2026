---
paths:
  - 'app/**'
---

# App

## A Team is a client business; portal access is separate from TeamRole
The starter kit's `Team` models a client business (Braemar Joinery), and its members are that business's staff. Projects, tickets, invoices and updates all hang off `team_id`; scope every client-facing query through `Auth::user()->currentTeam`.

Two role systems run side by side on purpose:
- `TeamRole` (owner/admin/member) governs team management, and is what the inherited team policies and tests use.
- `ClientAccess` (full/tickets/view) governs the portal, via `$user->accessFor()` and `EnsureUserHasClientAccess:billing|tickets|team`. Only `full` sees invoices and the plan billing strip.

`users.is_admin` marks studio staff; `EnsureUserIsStudioAdmin` guards `/admin/*`. Login lands admins on `admin.queue` and everyone else on `client.dashboard` — the team slug is no longer in any URL, so don't reintroduce `route('dashboard')`.

`StudioSetting::current()` is a singleton row; its defaults are declared on the model as well as the migration, because a freshly created row otherwise comes back with null attributes.
