<?php

use App\Actions\Clients\CreateClient;
use App\Models\Enquiry;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Enquiries')]
class extends Component {
    #[Url(as: 'show', except: 'new')]
    public string $filter = 'new';

    /** The enquiry whose "open an account" form is showing, if any. */
    public ?int $opening = null;

    public string $newBusiness = '';

    public string $newContactName = '';

    public string $newContactEmail = '';

    public string $newJobTitle = '';

    /**
     * Get the enquiries matching the current filter, newest first.
     *
     * @return Collection<int, Enquiry>
     */
    #[Computed]
    public function enquiries(): Collection
    {
        return Enquiry::query()
            ->with('team')
            ->when($this->filter === 'new', fn ($query) => $query->unhandled())
            ->when($this->filter === 'handled', fn ($query) => $query->handled())
            ->latest()
            ->get();
    }

    /**
     * Get the counts behind the filters, so the tabs can carry them.
     *
     * @return array{new: int, handled: int, all: int}
     */
    #[Computed]
    public function counts(): array
    {
        $all = Enquiry::query()->count();
        $new = Enquiry::query()->unhandled()->count();

        return ['new' => $new, 'handled' => $all - $new, 'all' => $all];
    }

    /**
     * Mark one as dealt with, or put it back in the pile.
     */
    public function setHandled(int $enquiryId, bool $handled): void
    {
        $enquiry = Enquiry::findOrFail($enquiryId);

        $enquiry->setHandled($handled);

        unset($this->enquiries, $this->counts);

        Flux::toast(variant: 'success', text: $handled
            ? __('Marked :name as dealt with.', ['name' => $enquiry->name])
            : __('Put :name back.', ['name' => $enquiry->name]));
    }

    /**
     * Bin one for good. Spam, mostly.
     */
    public function delete(int $enquiryId): void
    {
        $enquiry = Enquiry::findOrFail($enquiryId);

        $enquiry->delete();

        unset($this->enquiries, $this->counts);

        Flux::toast(variant: 'success', text: __('Deleted the enquiry from :name.', ['name' => $enquiry->name]));
    }

    /**
     * Show the account form against one enquiry, filled in from what they sent.
     *
     * The company they typed is the obvious business name; where they left it
     * blank, their own name is the better guess than nothing — a sole trader
     * usually is the business.
     */
    public function startAccount(int $enquiryId): void
    {
        $enquiry = Enquiry::findOrFail($enquiryId);

        abort_if($enquiry->becameClient(), 409);

        $this->opening = $enquiry->id;
        $this->newBusiness = $enquiry->company ?: $enquiry->name;
        $this->newContactName = $enquiry->name;
        $this->newContactEmail = $enquiry->email;
        $this->newJobTitle = '';

        $this->resetValidation();
    }

    /**
     * Put the account form away without opening anything.
     */
    public function cancelAccount(): void
    {
        $this->reset('opening', 'newBusiness', 'newContactName', 'newContactEmail', 'newJobTitle');
        $this->resetValidation();
    }

    /**
     * Turn the enquiry into a client account, and email them the welcome link.
     *
     * Goes through the same action as every other way of opening an account,
     * so the welcome email and the password link cannot drift apart from it.
     */
    public function openAccount(): void
    {
        $enquiry = Enquiry::findOrFail($this->opening);

        abort_if($enquiry->becameClient(), 409);

        $validated = $this->validate([
            'newBusiness' => ['required', 'string', 'max:255'],
            'newContactName' => ['required', 'string', 'max:255'],
            'newContactEmail' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'newJobTitle' => ['nullable', 'string', 'max:255'],
        ], [
            'newContactEmail.unique' => __('That address already has an account, so there is nothing to open.'),
        ]);

        $team = app(CreateClient::class)->handle(
            business: $validated['newBusiness'],
            contactName: $validated['newContactName'],
            contactEmail: $validated['newContactEmail'],
            jobTitle: $validated['newJobTitle'] ?: null,
            createdBy: Auth::user(),
        );

        // Opening an account is dealing with the enquiry, so it stops asking.
        $enquiry->update(['team_id' => $team->id, 'handled_at' => now()]);

        $this->cancelAccount();

        unset($this->enquiries, $this->counts);

        Flux::toast(variant: 'success', text: __(':business is set up. :name has been emailed to set a password.', [
            'business' => $team->name,
            'name' => $validated['newContactName'],
        ]));
    }

    /**
     * Build the mailto link for replying, with the subject already filled in.
     */
    public function replyLink(Enquiry $enquiry): string
    {
        return 'mailto:'.$enquiry->email.'?subject='.rawurlencode(
            __('Re: your enquiry to RSC Media')
        );
    }
}; ?>

<div wire:poll.60s>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">contact_form</x-rsc.kicker>
            <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Enquiries') }}</x-rsc.heading>
            <p class="mt-3 flex flex-wrap items-center gap-2.5 text-[15px] text-muted">
                @if ($this->counts['new'] > 0)
                    <x-rsc.pill tone="warm">{{ trans_choice('{1}1 waiting on you|[2,*]:count waiting on you', $this->counts['new'], ['count' => $this->counts['new']]) }}</x-rsc.pill>
                @else
                    <x-rsc.pill tone="brand">{{ __('Nothing waiting') }}</x-rsc.pill>
                @endif
                <span class="font-mono text-[11px]">{{ trans_choice('{0}none yet|{1}1 in total|[2,*]:count in total', $this->counts['all'], ['count' => $this->counts['all']]) }}</span>
            </p>
        </div>
    </div>

    <div class="mb-[18px] flex flex-wrap gap-2">
        @foreach ([['new', __('Waiting'), $this->counts['new']], ['handled', __('Dealt with'), $this->counts['handled']], ['all', __('All'), $this->counts['all']]] as [$value, $label, $count])
            <x-rsc.chip wire:click="$set('filter', '{{ $value }}')" :active="$filter === $value">
                {{ $label }} ({{ $count }})
            </x-rsc.chip>
        @endforeach
    </div>

    <div class="flex flex-col gap-[clamp(12px,1.4vw,18px)]">
        @forelse ($this->enquiries as $enquiry)
            <x-rsc.panel wire:key="enquiry-{{ $enquiry->id }}"
                         @class(['opacity-60' => $enquiry->isHandled()])>
                <div class="mb-3.5 flex flex-wrap items-center gap-2.5 font-mono text-[11px] tracking-[0.08em] text-muted">
                    <x-rsc.pill :tone="$enquiry->isHandled() ? 'muted' : 'warm'">
                        {{ $enquiry->isHandled() ? __('dealt with') : __('waiting') }}
                    </x-rsc.pill>
                    @if ($enquiry->becameClient())
                        <x-rsc.pill tone="brand">{{ __('now a client') }}</x-rsc.pill>
                    @endif
                    <x-rsc.pill tone="soft">{{ str($enquiry->topicLabel())->lower() }}</x-rsc.pill>
                    <span class="ms-auto">{{ __('received :when', ['when' => shown($enquiry->created_at)?->format('j M Y, H:i')]) }}</span>
                </div>

                <x-rsc.heading :level="2" class="!text-[clamp(18px,2vw,24px)]">
                    {{ $enquiry->name }}@if ($enquiry->company)<span class="text-muted"> · {{ $enquiry->company }}</span>@endif
                </x-rsc.heading>

                <p class="mt-3 text-sm leading-relaxed text-muted text-pretty">{{ $enquiry->message }}</p>

                <div class="mt-5 flex flex-wrap items-center gap-3.5">
                    <x-rsc.button as="a" href="{{ $this->replyLink($enquiry) }}" class="!px-5 !py-2.5 !text-sm">
                        {{ __('Reply to :email', ['email' => $enquiry->email]) }}
                    </x-rsc.button>

                    <button type="button" wire:click="setHandled({{ $enquiry->id }}, {{ $enquiry->isHandled() ? 'false' : 'true' }})"
                            class="cursor-pointer bg-transparent p-0 font-mono text-[11px] text-muted transition-colors hover:text-brand">
                        {{ $enquiry->isHandled() ? __('put it back') : __('mark as dealt with') }}
                    </button>

                    @if ($enquiry->becameClient())
                        <span class="font-mono text-[11px] text-muted">
                            {{ __('opened as :business', ['business' => $enquiry->team?->name]) }}
                        </span>
                    @elseif ($opening !== $enquiry->id)
                        <button type="button" wire:click="startAccount({{ $enquiry->id }})"
                                class="cursor-pointer bg-transparent p-0 font-mono text-[11px] text-muted transition-colors hover:text-brand">
                            {{ __('open an account') }}
                        </button>
                    @endif

                    <button type="button" wire:click="delete({{ $enquiry->id }})"
                            wire:confirm="{{ __('Delete the enquiry from :name? This cannot be undone.', ['name' => $enquiry->name]) }}"
                            class="cursor-pointer bg-transparent p-0 font-mono text-[11px] text-muted transition-colors hover:text-warm">
                        {{ __('delete') }}
                    </button>
                </div>

                @if ($opening === $enquiry->id)
                    <div class="mt-5 animate-rsc-fade border-t border-line pt-5">
                        <div class="mb-4 font-mono text-[11px] tracking-[0.08em] text-muted">open_an_account</div>

                        @include('pages.admin.partials.new-client-fields')

                        <div class="mt-[18px] flex flex-wrap items-center gap-3.5">
                            <x-rsc.button wire:click="openAccount" class="!px-5 !py-2.5 !text-sm">
                                {{ __('Open the account') }}
                            </x-rsc.button>
                            <button type="button" wire:click="cancelAccount"
                                    class="cursor-pointer bg-transparent p-0 font-mono text-[11px] text-muted transition-colors hover:text-body">
                                {{ __('cancel') }}
                            </button>
                            <span class="text-xs text-muted">
                                {{ __('They get an email to set their own password. The link lasts a week.') }}
                            </span>
                        </div>
                    </div>
                @endif
            </x-rsc.panel>
        @empty
            <x-rsc.panel>
                <p class="m-0 text-sm text-muted">
                    {{ match ($filter) {
                        'new' => __('Nothing waiting. Everything that has come in has been dealt with.'),
                        'handled' => __('Nothing has been marked as dealt with yet.'),
                        default => __('Nobody has used the contact form yet.'),
                    } }}
                </p>
            </x-rsc.panel>
        @endforelse
    </div>
</div>
