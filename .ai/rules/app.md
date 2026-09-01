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

## Invoices are raised in one place; only deposits and finals draw down a fixed price
Every invoice is created through `App\Actions\Billing\RaiseInvoice`, so the number, VAT rate and due date are worked out identically whether it came from a signed off proposal, a finished project, a chargeable ticket or the one-off admin form. Do not call `Invoice::create()` directly.

`Project::contractInvoiced()` counts only `Deposit` and `Final` invoices when working out `balanceToInvoice()`. A chargeable ticket billed against a project is extra to the fixed fee, and plan invoices are the separate monthly retainer — counting either silently under-bills the final invoice. This was a real bug: a £260 ticket reduced a £3,072 balance to £2,812.

`agreed_value` is the numeric contract total; `value_label` is display only. Null `agreed_value` means there is nothing fixed to bill against, such as a care plan, and the project stays out of the billing queue.

A ticket is billable when it is `Chargeable`, the client approved the quote, and no invoice references it — `Ticket::isReadyToInvoice()`. `invoices.ticket_id` is what stops it being billed twice.

## Money is per client, and never converted
Each client (Team) has a `currency` (App\Enums\Currency: GBP, EUR, USD, CAD; GBP is `Currency::Base`). Everything quoted or invoiced to that client is in it.

Never hardcode a currency symbol. Use `$team->money($amount)` for anything in a client's context, `$invoice->money($amount)` for an invoice, and `Currency::Base->format()` for the studio's own numbers (its default rates and plan prices).

Invoices snapshot `currency` from the client when raised (in RaiseInvoice), so moving a client to another currency never restates what has already been issued.

There are no exchange rates in this app. A studio default rate used by a non-GBP client is charged as that many of the client's currency — the settings screen warns about this rather than converting. Anything added up across clients must go through `App\Support\Money::total()`, which prints "£4,200 + $1,800 USD" rather than pretending mixed currencies are one number.
