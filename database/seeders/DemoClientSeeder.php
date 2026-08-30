<?php

namespace Database\Seeders;

use App\Enums\BillingMode;
use App\Enums\ClientAccess;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\ProjectPhase;
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
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the three demo clients that the Claude Design mock-ups were drawn around,
 * so both portals have something realistic to render straight after install.
 */
class DemoClientSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the demo studio, clients, projects, tickets and invoices.
     */
    public function run(): void
    {
        $care = Plan::where('slug', 'plan_02')->firstOrFail();
        $essential = Plan::where('slug', 'plan_01')->firstOrFail();

        $ross = User::factory()->admin()->create([
            'name' => 'Ross Mackay',
            'email' => 'ross@rscmedia.co.uk',
        ]);

        $braemar = $this->client('Braemar Joinery', $care, [
            'billing_email' => 'accounts@braemarjoinery.co.uk',
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
            'title' => 'Quote and job tracker',
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
            'title' => 'Booking system phase 2',
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
            'title' => 'Site rebuild',
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

        $this->ticket($glencoe, $booking, $morag, [
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

        Attachment::insert([
            $this->file($vatTicket, $ross, 'quote-vat-fix-estimate.pdf', 'PDF', 86_016, true),
            $this->file($vatTicket, $kirsty, 'vat-rate-config.png', 'PNG', 421_888, true),
            $this->file($vatTicket, $ross, 'internal-notes-tax-table.md', 'MD', 3_072, false),
        ]);

        $this->updates($braemar, $tracker);

        $this->invoices($braemar, $glencoe, $fettes, $tracker, $booking, $rebuild, $carePlan);
    }

    /**
     * Create a client business with its people.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{0: string, 1: string, 2: string, 3: TeamRole, 4: ClientAccess}>  $people
     */
    private function client(string $name, Plan $plan, array $attributes, array $people): Team
    {
        $team = Team::factory()->create([
            'name' => $name,
            'plan_id' => $plan->id,
            ...$attributes,
        ]);

        foreach ($people as [$personName, $email, $jobTitle, $role, $access]) {
            $user = User::create([
                'name' => $personName,
                'email' => $email,
                'password' => 'password',
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

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
    private function file(Ticket $ticket, User $uploader, string $name, string $kind, int $size, bool $shared): array
    {
        return [
            'attachable_type' => Ticket::class,
            'attachable_id' => $ticket->id,
            'uploaded_by' => $uploader->id,
            'name' => $name,
            'kind' => $kind,
            'size' => $size,
            'shared_with_client' => $shared,
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
