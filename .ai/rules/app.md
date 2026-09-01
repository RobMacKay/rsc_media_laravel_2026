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

## New accounts go through the welcome wizard once
`users.onboarded_at` gates the wizard at `pages::onboarding` (`/welcome`). `EnsureUserHasOnboarded` sits on the client route group and bounces anyone with a null value; studio admins are exempt, and the wizard's own route is outside the group so it cannot loop.

Skipping sets `onboarded_at` too — the wizard promises everything is changeable from settings, so it must not nag. There is one setter, `finish()`.

`UserFactory` creates an established account (`onboarded_at` set). Use `->brandNew()` to test the wizard, otherwise every client-area test 302s to it.

Owners (`ClientAccess::Full`) get company → details → team; someone who joined on an invite gets only their own details, because their owner already did the other two. `steps()` is the single source for that, and `saveCompany()`/`invite()` re-check it with `abort_unless`.

`teams.systems` is what the client says the studio looks after, and feeds the ticket form's system dropdown. `team_invitations.name` and `.access` are honoured by `CreateNewUser`, so the access chosen when inviting is what the person actually joins on.

## Attached files are private and served through one route
Uploads go through `App\Actions\Attachments\StoreAttachment` — the one place a file is written. It stores on the `local` (private) disk under `attachments/{type}/{id}/{ulid}.{ext}`: the name on disk is a ULID so nothing user-supplied touches the filesystem and identical filenames cannot collide. The original name is kept in the `name` column for display and for the download filename.

Nothing is publicly reachable. `AttachmentDownloadController` (`GET /files/{attachment}`) is the only way to get a file, and it checks `Attachment::isVisibleTo()`: studio staff see everything, a client sees only their own team's files and only where `shared_with_client` is true. Deleting the row deletes the file, via a `deleted` hook on the model.

Anything attachable implements `App\Contracts\HasAttachments`, which exists to supply `teamId()` — that is what decides who may download.

Never hardcode a size limit. `Attachment::maxUploadKb()` takes the smallest of `upload_max_filesize`, `post_max_size` and our own constant, so the hint in the UI and the validation rule both tell the truth on whatever server is running. PHP rejects an oversized upload before Laravel sees it, so promising more than the server accepts fails with nothing useful to show.

## Ticket update badges, and never mention VAT unless it is charged
`ticket_reads` holds when each person last opened each ticket. `Ticket::hasUpdateFor($user)` compares it to `updated_at`, and a ticket nobody has opened counts as an update — a new ticket to the studio, a colleague's ticket to a client. Load with the `withReadsFor($user)` scope so a list does not query per row.

Mark read on open **and after every action that touches the ticket**, in both `pages::client.tickets` and `pages::admin.queue` (`markOpenTicketRead()` / `markCurrentRead()`). Comments and quotes call `touch()`, so without that your own reply bumps `updated_at` past your own read mark and the ticket comes straight back flagged as new to the person who just wrote it.

`Ticket::awaitingQuoteResponse()` is the scope behind the "needs you" badge and the header counts; it mirrors `hasQuoteAwaitingResponse()` so a count and a row badge cannot disagree.

Nothing user-facing may mention VAT unless `StudioSetting::chargesVat()` — the studio is not registered today and wants the option later. That follows `effectiveVatRate()`, so a figure and its label always agree. Invoice documents gate on the invoice's own snapshotted `vat_rate` instead, so an invoice raised while registered keeps its VAT line afterwards.

`StudioSetting` is bound in the container to `current()`. Without it, anything type-hinting the model gets a blank one and quietly bills the class defaults for VAT and payment terms rather than the studio's saved settings.
