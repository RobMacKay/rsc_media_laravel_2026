<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\ProjectPhase;
use App\Enums\ProposalStatus;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A piece of work bigger than a ticket: the client's request, the proposal the
 * studio writes back, and the sign-off that turns it into a project.
 *
 * @property int $id
 * @property string $reference
 * @property int $team_id
 * @property int|null $requested_by
 * @property int|null $project_id
 * @property string $title
 * @property string $brief
 * @property string|null $goal
 * @property string|null $budget_guide
 * @property string|null $needed_by
 * @property string|null $contact
 * @property string|null $scope
 * @property string|null $phases
 * @property string|null $excluded
 * @property int $price
 * @property int $deposit_percent
 * @property int $weeks
 * @property ProposalStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $requester
 * @property-read Project|null $project
 */
#[Fillable([
    'reference', 'team_id', 'requested_by', 'project_id', 'title', 'brief', 'goal',
    'budget_guide', 'needed_by', 'contact', 'scope', 'phases', 'excluded',
    'price', 'deposit_percent', 'weeks', 'status', 'sent_at', 'responded_at',
])]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    /**
     * The budget brackets offered on the client's proposal form.
     *
     * @var array<int, string>
     */
    public const BUDGETS = ['Under £1k', '£1k–£3k', '£3k–£7k', '£7k+', 'No idea yet'];

    /**
     * Allocate the next sequential reference, shared with projects so a signed
     * off proposal keeps the number the client already knows it by.
     */
    public static function nextReference(): string
    {
        $latest = max(
            (int) str(static::query()->max('reference') ?? 'PRJ-000')->afterLast('-')->toString(),
            (int) str(Project::query()->max('reference') ?? 'PRJ-000')->afterLast('-')->toString(),
        );

        return 'PRJ-'.str_pad((string) ($latest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the client this proposal belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the person who asked for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the project this proposal turned into once it was signed off.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope to the proposals still moving between the client and the studio.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', [ProposalStatus::Submitted, ProposalStatus::Sent]);
    }

    /**
     * Get the scope as one entry per bullet.
     *
     * @return array<int, string>
     */
    public function scopeLines(): array
    {
        return $this->splitLines($this->scope);
    }

    /**
     * Get the phases as "name | date | note" rows, ignoring malformed lines.
     *
     * @return array<int, array{name: string, date: string, note: string}>
     */
    public function phaseRows(): array
    {
        return array_values(array_filter(array_map(function (string $line): ?array {
            [$name, $date, $note] = array_pad(array_map('trim', explode('|', $line, 3)), 3, '');

            return $name === '' ? null : ['name' => $name, 'date' => $date, 'note' => $note];
        }, $this->splitLines($this->phases))));
    }

    /**
     * Get the deposit payable when the client signs off, excluding VAT.
     */
    public function deposit(): float
    {
        return $this->price * $this->deposit_percent / 100;
    }

    /**
     * Get the balance payable on go live, excluding VAT.
     */
    public function balance(): float
    {
        return $this->price - $this->deposit();
    }

    /**
     * Sign the proposal off: open the project it describes and raise the
     * deposit invoice, so the client's approval actually starts the work.
     */
    public function approve(StudioSetting $settings): Project
    {
        return DB::transaction(function () use ($settings) {
            // Re-read under a lock: the caller checks the status, but two
            // sign-offs racing would both pass that check and the second would
            // collide on the unique project reference.
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked?->status !== ProposalStatus::Sent) {
                throw new RuntimeException("Proposal {$this->reference} is not out for sign-off.");
            }

            $project = Project::create([
                'reference' => $this->reference,
                'team_id' => $this->team_id,
                'title' => $this->title,
                'summary' => $this->brief,
                'phase' => ProjectPhase::Scoping,
                'percent' => 0,
                'milestone' => $this->phaseRows()[0]['name'] ?? 'Kick-off call',
                'due_on' => now()->addWeeks(max($this->weeks, 1)),
                'value_label' => '£'.number_format($this->price).' fixed',
            ]);

            $this->update([
                'status' => ProposalStatus::Approved,
                'responded_at' => now(),
                'project_id' => $project->id,
            ]);

            Invoice::create([
                'number' => Invoice::nextNumber(),
                'team_id' => $this->team_id,
                'project_id' => $project->id,
                'type' => InvoiceType::Deposit,
                'note' => $this->title.' — '.$this->deposit_percent.'% deposit',
                'amount' => (int) round($this->deposit()),
                'vat_rate' => $settings->effectiveVatRate(),
                'issued_on' => now(),
                'due_on' => now()->addDays($this->team->effectivePaymentTerms($settings)),
                'status' => InvoiceStatus::Sent,
            ]);

            return $project;
        });
    }

    /**
     * Split a textarea's contents into trimmed, non-empty lines.
     *
     * @return array<int, string>
     */
    private function splitLines(?string $value): array
    {
        return collect(preg_split('/\R/', (string) $value) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
