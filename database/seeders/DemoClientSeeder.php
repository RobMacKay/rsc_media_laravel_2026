<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\BillingMode;
use App\Enums\ClientAccess;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\ProjectPhase;
use App\Enums\ProposalStatus;
use App\Enums\QuoteResponse;
use App\Enums\SiteStatus;
use App\Enums\TeamRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\UpdateKind;
use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Proposal;
use App\Models\SiteCheck;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds the three demo clients that the Claude Design mock-ups were drawn around,
 * so both portals have something realistic to render straight after install.
 */
class DemoClientSeeder extends Seeder
{
    /**
     * The password every demo account is created with.
     */
    private const DEMO_PASSWORD = 'password';

    /**
     * Seed the demo studio, clients, projects, tickets and invoices.
     */
    public function run(): void
    {
        $care = Plan::where('slug', 'plan_02')->firstOrFail();
        $essential = Plan::where('slug', 'plan_01')->firstOrFail();

        $ross = $this->person('Ross Mackay', 'ross@rscmedia.co.uk', isAdmin: true);

        app(CreateTeam::class)->handle($ross, 'RSC Media', isPersonal: true);

        $braemar = $this->client('Braemar Joinery', $care, [
            'billing_email' => 'accounts@braemarjoinery.co.uk',
            'company_number' => 'SC221084',
            'address' => "Unit 4, Lochy Industrial Estate\nFort William\nPH33 6TQ",
            'vat_number' => 'GB334512908',
            'systems' => ['braemarjoinery.co.uk', 'Quote and job tracker', 'Trade counter stock pages'],
            'support_hours' => 6,
        ], [
            ['Kirsty Munro', 'kirsty@braemarjoinery.co.uk', 'Office manager', TeamRole::Owner, ClientAccess::Full],
            ['Alan Munro', 'alan@braemarjoinery.co.uk', 'Director', TeamRole::Admin, ClientAccess::Full],
            ['Sam Docherty', 'sam@braemarjoinery.co.uk', 'Workshop lead', TeamRole::Member, ClientAccess::Tickets],
        ]);

        $glencoe = $this->client('Glen Coe Cabins', $care, [
            'billing_email' => 'morag@glencoecabins.co.uk',
            'hour_rate' => 75,
            'support_hours' => 2,
            'payment_terms_days' => 14,
        ], [
            ['Morag Bell', 'morag@glencoecabins.co.uk', 'Owner', TeamRole::Owner, ClientAccess::Full],
        ]);

        $fettes = $this->client('Fettes Dental', $essential, [
            'billing_email' => 'finance@fettesdental.co.uk',
            'purchase_order_ref' => 'FD-2026-114',
            'day_rate' => 520,
            'support_hours' => 4,
            'payment_terms_days' => 30,
        ], [
            ['Priya Shah', 'priya@fettesdental.co.uk', 'Practice manager', TeamRole::Owner, ClientAccess::Full],
        ]);

        $tracker = $this->project($braemar, [
            'reference' => 'PRJ-004',
            'title' => 'Quote and job tracker',
            'agreed_value' => 6600,
            'summary' => 'Internal tool replacing the shared spreadsheet.',
            'phase' => ProjectPhase::Build,
            'percent' => 65,
            'milestone' => 'Test site for review',
            'due_on' => '2026-08-21',
            'waiting_on_client' => 'Sign off the quote PDF layout',
            'hours_used' => 38,
            'hours_budgeted' => 55,
            'value_label' => '£6,600 fixed',
        ]);

        $carePlan = $this->project($braemar, [
            'reference' => 'PRJ-005',
            'title' => 'Hosting and care plan',
            'summary' => 'Ongoing hosting, updates and support hours.',
            'phase' => ProjectPhase::Live,
            'percent' => 100,
            'milestone' => 'Monthly check',
            'due_on' => '2026-09-01',
            'hours_used' => 2.5,
            'hours_budgeted' => 6,
            'value_label' => '£180 / month',
        ]);

        $booking = $this->project($glencoe, [
            'reference' => 'PRJ-003',
            'title' => 'Booking system phase 2',
            'agreed_value' => 3600,
            'summary' => 'Deposits and confirmation emails.',
            'phase' => ProjectPhase::Scoping,
            'percent' => 15,
            'milestone' => 'Deposit flow spec agreed',
            'due_on' => '2026-09-04',
            'waiting_on_client' => 'Deposit percentage confirmed',
            'hours_used' => 4,
            'hours_budgeted' => 30,
            'value_label' => 'Estimate £3,600',
        ]);

        $rebuild = $this->project($fettes, [
            'reference' => 'PRJ-001',
            'title' => 'Site rebuild',
            'agreed_value' => 7680,
            'summary' => 'New marketing site and booking hand-off.',
            'phase' => ProjectPhase::Testing,
            'percent' => 90,
            'milestone' => 'Go live',
            'due_on' => '2026-08-29',
            'hours_used' => 61,
            'hours_budgeted' => 64,
            'value_label' => '£7,680 fixed',
        ]);

        $kirsty = $braemar->members()->where('email', 'kirsty@braemarjoinery.co.uk')->firstOrFail();
        $alan = $braemar->members()->where('email', 'alan@braemarjoinery.co.uk')->firstOrFail();
        $morag = $glencoe->members()->first();
        $priya = $fettes->members()->first();

        $vatTicket = $this->ticket($braemar, $tracker, $kirsty, [
            'reference' => 'RSC-1048',
            'title' => 'Quote PDF shows last year’s VAT rate',
            'description' => 'The PDF that comes off the quote screen still has 19% on it. Customer spotted it before we did. Everything else on the quote is right.',
            'system' => 'Quote and job tracker',
            'page_url' => '/quotes/print',
            'type' => TicketType::Bug,
            'priority' => TicketPriority::High,
            'status' => TicketStatus::InProgress,
            'quoted_hours' => 1.5,
            'quoted_rate' => 65,
            'billing_mode' => BillingMode::SupportHours,
            'created_at' => '2026-08-12 08:40:00',
            'updated_at' => '2026-08-12 10:40:00',
        ]);

        $this->ticket($braemar, $tracker, $kirsty, [
            'reference' => 'RSC-1047',
            'title' => 'Add Callum to the tracker as a user',
            'description' => 'New apprentice starting Monday. Needs to see jobs but not pricing.',
            'system' => 'Quote and job tracker',
            'type' => TicketType::Change,
            'priority' => TicketPriority::Normal,
            'status' => TicketStatus::WaitingOnClient,
            'created_at' => '2026-08-07 09:20:00',
            'updated_at' => '2026-08-11 09:20:00',
        ]);

        $this->ticket($braemar, null, $alan, [
            'reference' => 'RSC-1046',
            'title' => 'Contact form emails going to spam',
            'description' => 'Enquiries are landing in junk on Outlook. Gmail seems fine. Started roughly when the hosting moved.',
            'system' => 'braemarjoinery.co.uk',
            'page_url' => '/contact',
            'type' => TicketType::Bug,
            'priority' => TicketPriority::High,
            'status' => TicketStatus::Open,
            'created_at' => '2026-08-09 16:02:00',
            'updated_at' => '2026-08-09 16:02:00',
        ]);

        $this->ticket($braemar, null, $kirsty, [
            'reference' => 'RSC-1044',
            'title' => 'Change the opening hours on the footer',
            'description' => 'We now close at 4pm on a Friday.',
            'system' => 'braemarjoinery.co.uk',
            'type' => TicketType::Change,
            'priority' => TicketPriority::Low,
            'status' => TicketStatus::Resolved,
            'resolved_at' => '2026-08-06 12:00:00',
            'created_at' => '2026-08-04 09:00:00',
            'updated_at' => '2026-08-06 12:00:00',
        ]);

        $quoteTicket = $this->ticket($glencoe, $booking, $morag, [
            'reference' => 'RSC-1045',
            'title' => 'Add a deposit field to the booking flow',
            'description' => 'We want to take 20% up front rather than the full amount. Needs to show on the confirmation email too.',
            'system' => 'Booking system',
            'page_url' => '/book/step-2',
            'type' => TicketType::Change,
            'priority' => TicketPriority::Normal,
            'status' => TicketStatus::QuoteSent,
            'quoted_hours' => 6,
            'quoted_rate' => 75,
            'billing_mode' => BillingMode::Chargeable,
            'quote_sent_at' => '2026-08-08 12:00:00',
            'created_at' => '2026-08-08 11:15:00',
            'updated_at' => '2026-08-08 12:00:00',
        ]);

        $this->ticket($glencoe, $booking, $morag, [
            'reference' => 'RSC-1041',
            'title' => 'Booking form drops the phone number field',
            'description' => 'Number typed in was not coming through on the confirmation email. Fixed and tested.',
            'system' => 'Booking system',
            'page_url' => '/book/step-1',
            'type' => TicketType::Bug,
            'priority' => TicketPriority::Normal,
            'status' => TicketStatus::Resolved,
            'resolved_at' => '2026-07-29 15:00:00',
            'created_at' => '2026-07-28 14:10:00',
            'updated_at' => '2026-07-29 15:00:00',
        ]);

        $this->ticket($fettes, $rebuild, $priya, [
            'reference' => 'RSC-1043',
            'title' => 'Cookie banner covers the phone number on mobile',
            'description' => 'On an iPhone the banner sits right over the call button. Patients are ringing the wrong practice.',
            'system' => 'fettesdental.co.uk',
            'page_url' => '/',
            'type' => TicketType::Bug,
            'priority' => TicketPriority::Urgent,
            'status' => TicketStatus::Open,
            'created_at' => '2026-08-06 19:44:00',
            'updated_at' => '2026-08-06 19:44:00',
        ]);

        $this->ticket($fettes, $rebuild, $priya, [
            'reference' => 'RSC-1042',
            'title' => 'Add an online deposit to the booking form',
            'description' => 'Patients want to pay a £25 holding deposit when they book a hygienist slot.',
            'system' => 'fettesdental.co.uk',
            'page_url' => '/book',
            'type' => TicketType::Change,
            'priority' => TicketPriority::Normal,
            'status' => TicketStatus::Resolved,
            'quoted_hours' => 4,
            'quoted_rate' => 65,
            'billing_mode' => BillingMode::Chargeable,
            'quote_sent_at' => '2026-08-04 10:00:00',
            'quote_response' => QuoteResponse::Approved,
            'quote_responded_at' => '2026-08-05 09:15:00',
            'resolved_at' => '2026-08-14 16:30:00',
            'created_at' => '2026-08-03 13:20:00',
            'updated_at' => '2026-08-14 16:30:00',
        ]);

        $this->sitesFor($braemar, [
            ['Main website', 'braemarjoinery.co.uk', 'up', 74],
            ['Quote and job tracker', 'quotes.braemarjoinery.co.uk', 'up', 12],
            ['Trade counter stock', 'stock.braemarjoinery.co.uk', 'down', 40],
        ]);

        $this->sitesFor($glencoe, [
            ['Main website', 'glencoecabins.co.uk', 'up', 88],
            ['Booking system', 'book.glencoecabins.co.uk', 'up', 5],
        ]);

        $this->sitesFor($fettes, [
            ['Practice website', 'fettesdental.co.uk', 'up', 120],
        ]);

        Attachment::insert([
            $this->file($vatTicket, $ross, 'quote-vat-fix-estimate.pdf', 'PDF', true),
            $this->file($vatTicket, $kirsty, 'vat-rate-config.png', 'PNG', true),
            $this->file($vatTicket, $ross, 'internal-notes-tax-table.md', 'MD', false),
        ]);

        $this->project($braemar, [
            'reference' => 'PRJ-002',
            'title' => 'Braemar Joinery website',
            'agreed_value' => 5900,
            'summary' => 'Main site, timber calculator and enquiry forms.',
            'phase' => ProjectPhase::Live,
            'percent' => 100,
            'milestone' => 'Complete',
            'due_on' => '2026-03-04',
            'completed_on' => '2026-03-04',
            'hours_used' => 52,
            'hours_budgeted' => 52,
            'value_label' => '£5,900 fixed',
        ]);

        $this->proposals($braemar, $kirsty, $alan, $glencoe, $morag);

        $this->conversation($vatTicket, [
            [$kirsty, 'Customer spotted it on a quote we sent this morning, so it is a bit urgent. Happy for you to just fix it.', false, '2026-08-12 08:44:00'],
            [$ross, 'Found it — the VAT rate is hard coded in the PDF template rather than read from settings. Fixing it properly so it follows the rate you set.', false, '2026-08-12 09:30:00'],
            [$ross, 'Tax table needs migrating to the settings row before this can be closed off.', true, '2026-08-12 09:32:00'],
            [$kirsty, 'Perfect, thanks Ross.', false, '2026-08-12 10:40:00'],
        ]);

        $this->conversation($quoteTicket, [
            [$morag, 'We want to take 20% up front rather than the full amount. Needs to show on the confirmation email too.', false, '2026-08-08 11:16:00'],
            [$ross, 'Six hours covers the deposit field, the confirmation email and testing it end to end. Quote is on the ticket for you to approve.', false, '2026-08-08 12:00:00'],
        ]);

        $this->updates($braemar, $tracker);

        $this->invoices($braemar, $glencoe, $fettes, $tracker, $booking, $rebuild, $carePlan);
    }

    /**
     * Give a client some sites to watch, with a fortnight of check history so
     * the uptime figures and the downloadable log are not empty.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: int}>  $sites
     */
    private function sitesFor(Team $team, array $sites): void
    {
        foreach ($sites as [$name, $host, $state, $certificateDays]) {
            $isUp = $state === 'up';

            $site = $team->sites()->create([
                'name' => $name,
                'url' => 'https://'.$host,
                'host' => $host,
                'status' => $isUp ? SiteStatus::Up : SiteStatus::Down,
                'http_status' => $isUp ? 200 : 503,
                'response_ms' => $isUp ? random_int(120, 720) : null,
                'ssl_valid' => true,
                'ssl_expires_at' => now()->addDays($certificateDays),
                'ssl_issuer' => "Let's Encrypt",
                'last_error' => $isUp ? null : 'The site answered with 503.',
                'last_checked_at' => now()->subMinutes(4),
                'last_up_at' => $isUp ? now()->subMinutes(4) : now()->subHours(3),
                'last_down_at' => $isUp ? now()->subDays(9) : now()->subMinutes(4),
                'consecutive_failures' => $isUp ? 0 : 12,
                'down_notified_at' => $isUp ? null : now()->subHours(3),
            ]);

            $rows = [];

            // Every fifteen minutes for a fortnight, with the odd blip so the
            // uptime percentage is a real number rather than a flat 100%.
            for ($minutes = 14 * 24 * 60; $minutes >= 0; $minutes -= 15) {
                $at = now()->subMinutes($minutes);
                $failed = (! $isUp && $minutes < 180) || random_int(1, 260) === 1;

                $rows[] = [
                    'site_id' => $site->id,
                    'checked_at' => $at,
                    'status' => $failed ? SiteStatus::Down->value : SiteStatus::Up->value,
                    'http_status' => $failed ? 503 : 200,
                    'response_ms' => $failed ? null : random_int(110, 900),
                    'ssl_valid' => true,
                    'ssl_expires_at' => now()->addDays($certificateDays),
                    'error' => $failed ? 'The site answered with 503.' : null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                SiteCheck::insert($chunk);
            }
        }
    }

    /**
     * Create a verified demo user with a known password.
     */
    private function person(string $name, string $email, bool $isAdmin = false): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => self::DEMO_PASSWORD,
            'is_admin' => $isAdmin,
        ]);

        // Demo accounts are established clients, not brand new sign-ups, so
        // they skip the welcome wizard. Register a new account to see it.
        $user->forceFill([
            'email_verified_at' => now(),
            'onboarded_at' => now()->subMonths(6),
        ])->save();

        return $user;
    }

    /**
     * Create a client business with its people.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{0: string, 1: string, 2: string, 3: TeamRole, 4: ClientAccess}>  $people
     */
    private function client(string $name, Plan $plan, array $attributes, array $people): Team
    {
        $team = Team::create([
            'name' => $name,
            'plan_id' => $plan->id,
            ...$attributes,
        ]);

        foreach ($people as [$personName, $email, $jobTitle, $role, $access]) {
            $user = $this->person($personName, $email);

            $team->members()->attach($user, [
                'role' => $role->value,
                'access' => $access->value,
                'job_title' => $jobTitle,
            ]);

            $user->switchTeam($team);
        }

        return $team;
    }

    /**
     * Create one of the client's projects.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function project(Team $team, array $attributes): Project
    {
        return Project::create(['team_id' => $team->id, ...$attributes]);
    }

    /**
     * Create one of the client's tickets.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(Team $team, ?Project $project, User $reporter, array $attributes): Ticket
    {
        return Ticket::create([
            'team_id' => $team->id,
            'project_id' => $project?->id,
            'reported_by' => $reporter->id,
            ...$attributes,
        ]);
    }

    /**
     * Build one attachment row for a bulk insert.
     *
     * @return array<string, mixed>
     */
    private function file(Ticket $ticket, User $uploader, string $name, string $kind, bool $shared): array
    {
        // A real file on the private disk, so the demo's download links work
        // rather than 404ing the moment anyone clicks one.
        $path = 'attachments/tickets/'.$ticket->id.'/'.Str::ulid().'.'.Str::lower(Str::afterLast($name, '.'));

        Storage::disk('local')->put($path, str_repeat('RSC Media demo file. ', 24)."\n".$name."\n");

        return [
            'attachable_type' => Ticket::class,
            'attachable_id' => $ticket->id,
            'uploaded_by' => $uploader->id,
            'name' => $name,
            'path' => $path,
            'kind' => $kind,
            'size' => Storage::disk('local')->size($path),
            'shared_with_client' => $shared,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Seed the proposal pipeline: one waiting to be written up for each of two
     * clients, and one already out with the client for sign-off.
     */
    private function proposals(Team $braemar, User $kirsty, User $alan, Team $glencoe, User $morag): void
    {
        Proposal::create([
            'reference' => 'PRJ-007',
            'team_id' => $braemar->id,
            'requested_by' => $kirsty->id,
            'title' => 'Trade counter stock pages',
            'brief' => 'Trade customers keep phoning to ask what timber we have in. We want them to see live stock and their own pricing without ringing the counter.',
            'goal' => 'Would save the counter about an hour a day and stop us quoting on stock we have already sold.',
            'budget_guide' => '£3k–£7k',
            'needed_by' => 'before the October rush',
            'contact' => 'Kirsty Munro',
            'scope' => implode("\n", [
                'Stock list page reading live quantities from the job tracker',
                'Trade pricing shown only to signed-in trade accounts',
                'Search and filter by timber type, length and grade',
                'Weekly export for the counter staff to print',
                'Staff guide and one training session',
            ]),
            'phases' => implode("\n", [
                'Scoping | 18 Aug | Field list agreed, sample data from the tracker.',
                'Build | 1 Sep | Pages, pricing rules and trade sign-in.',
                'Testing | 12 Sep | Counter staff try it for a week on real stock.',
                'Live | 15 Sep | Switch on, training session, two weeks of snagging.',
            ]),
            'excluded' => 'Photography, and any changes to the counter till system.',
            'price' => 3400,
            'deposit_percent' => 40,
            'weeks' => 5,
            'status' => ProposalStatus::Sent,
            'sent_at' => Carbon::parse('2026-08-11'),
            'created_at' => Carbon::parse('2026-07-28'),
        ]);

        Proposal::create([
            'reference' => 'PRJ-006',
            'team_id' => $braemar->id,
            'requested_by' => $alan->id,
            'title' => 'Van fleet booking board',
            'brief' => 'A shared board so the yard can see which vans are out and when they are back. At the moment it is a whiteboard nobody updates.',
            'goal' => 'Stops two drivers turning up for the same van.',
            'budget_guide' => '£1k–£3k',
            'needed_by' => 'before the October rush',
            'contact' => 'Alan Munro',
            'deposit_percent' => 40,
            'weeks' => 3,
            'status' => ProposalStatus::Submitted,
            'created_at' => Carbon::parse('2026-08-06'),
        ]);

        Proposal::create([
            'reference' => 'PRJ-009',
            'team_id' => $glencoe->id,
            'requested_by' => $morag->id,
            'title' => 'Gift voucher checkout',
            'brief' => 'People ring up at Christmas wanting to buy a night as a present. We would like them to buy a voucher online and print it themselves.',
            'goal' => 'Roughly forty vouchers a year done by hand at the moment.',
            'budget_guide' => '£1k–£3k',
            'needed_by' => 'live by November',
            'contact' => 'Morag Bell',
            'deposit_percent' => 30,
            'weeks' => 4,
            'status' => ProposalStatus::Submitted,
            'created_at' => Carbon::parse('2026-08-11'),
        ]);
    }

    /**
     * Put a few messages on a ticket so the thread is not empty in the demo.
     *
     * @param  array<int, array{0: User, 1: string, 2: bool, 3: string}>  $messages
     */
    private function conversation(Ticket $ticket, array $messages): void
    {
        foreach ($messages as [$author, $body, $internal, $at]) {
            TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'body' => $body,
                'is_internal' => $internal,
                'created_at' => Carbon::parse($at),
                'updated_at' => Carbon::parse($at),
            ]);
        }
    }

    /**
     * Post the portal updates shown down the right of the client dashboard.
     */
    private function updates(Team $team, Project $project): void
    {
        $updates = [
            [UpdateKind::Project, 'quote tracker', '2026-08-11', 'Job list screen is on the test site', 'Filtering by fitter and by week is in. Search comes next week once the quote PDF is signed off.', $project],
            [UpdateKind::Studio, 'studio', '2026-08-08', 'Away Thursday afternoon', 'Back Friday morning. Anything urgent, use WhatsApp and I’ll pick it up.', null],
            [UpdateKind::Project, 'braemarjoinery.co.uk', '2026-08-05', 'Moved hosting to the new server', 'Pages load about twice as fast. Nothing for you to do, but tell me if anything looks off.', null],
            [UpdateKind::Studio, 'studio', '2026-08-01', 'Support hours reset', 'August allowance is available. Unused hours from July don’t carry over.', null],
        ];

        foreach ($updates as [$kind, $tag, $date, $title, $body, $related]) {
            ProjectUpdate::create([
                'team_id' => $team->id,
                'project_id' => $related?->id,
                'kind' => $kind,
                'tag' => $tag,
                'title' => $title,
                'body' => $body,
                'published_at' => Carbon::parse($date),
            ]);
        }
    }

    /**
     * Raise the invoices shown on both the client and admin billing screens.
     */
    private function invoices(
        Team $braemar,
        Team $glencoe,
        Team $fettes,
        Project $tracker,
        Project $booking,
        Project $rebuild,
        Project $carePlan,
    ): void {
        $rows = [
            ['RSC-0129', $braemar, null, InvoiceType::AdHoc, 'Hosting migration', 350, '2026-05-19', InvoiceStatus::Paid],
            ['RSC-0134', $braemar, $carePlan, InvoiceType::Plan, 'Care & Support — June', 180, '2026-06-01', InvoiceStatus::Paid],
            ['RSC-0138', $braemar, $carePlan, InvoiceType::Plan, 'Care & Support — July', 180, '2026-07-01', InvoiceStatus::Paid],
            ['RSC-0141', $braemar, null, InvoiceType::AdHoc, 'Extra training session', 260, '2026-07-07', InvoiceStatus::Paid],
            ['RSC-0142', $braemar, $tracker, InvoiceType::Deposit, 'Quote and job tracker — 40% deposit', 2640, '2026-07-14', InvoiceStatus::Paid],
            ['RSC-0143', $braemar, $carePlan, InvoiceType::Plan, 'Care & Support — August', 180, '2026-08-01', InvoiceStatus::Sent],
            ['RSC-0144', $fettes, null, InvoiceType::Plan, 'Essential — August', 75, '2026-08-01', InvoiceStatus::Paid],
            ['RSC-0145', $fettes, $rebuild, InvoiceType::Final, 'Site rebuild — balance on completion', 4608, '2026-07-19', InvoiceStatus::Overdue],
            ['RSC-0146', $glencoe, null, InvoiceType::Plan, 'Care & Support — August', 180, '2026-08-01', InvoiceStatus::Sent],
            ['RSC-0147', $glencoe, $booking, InvoiceType::Deposit, 'Booking system phase 2 — 30% deposit', 1080, '2026-08-05', InvoiceStatus::Draft],
        ];

        foreach ($rows as [$number, $team, $project, $type, $note, $amount, $issued, $status]) {
            $issuedOn = Carbon::parse($issued);

            Invoice::create([
                'number' => $number,
                'team_id' => $team->id,
                'project_id' => $project?->id,
                'type' => $type,
                'note' => $note,
                'amount' => $amount,
                'vat_rate' => 20,
                'issued_on' => $issuedOn,
                'due_on' => $issuedOn->copy()->addDays($team->payment_terms_days ?? 21),
                'status' => $status,
                'paid_at' => $status === InvoiceStatus::Paid ? $issuedOn->copy()->addDays(12) : null,
            ]);
        }
    }
}
