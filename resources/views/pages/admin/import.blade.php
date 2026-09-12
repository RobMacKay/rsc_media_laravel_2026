<?php

use App\Actions\Billing\ImportInvoices;
use App\Exceptions\ImportException;
use App\Models\Attachment;
use App\Models\StudioSetting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new
#[Layout('layouts::rsc.admin')]
#[Title('Import')]
class extends Component {
    use WithFileUploads;

    /** The Invoice Ninja invoice export. */
    public ?TemporaryUploadedFile $invoices = null;

    /**
     * The separate payments export, which is the only place the dates live. Optional,
     * because it is a different report and may well arrive later than the invoices did.
     */
    public ?TemporaryUploadedFile $payments = null;

    /**
     * What a dry run said would happen, held between the preview and the confirmation.
     *
     * @var array<string, mixed>|null
     */
    public ?array $previewed = null;

    /**
     * What actually happened, once it has.
     *
     * @var array<string, mixed>|null
     */
    public ?array $finished = null;

    /**
     * Work out what the uploaded export would do, without writing any of it.
     */
    public function preview(): void
    {
        $this->finished = null;
        $this->previewed = $this->run(dryRun: true);
    }

    /**
     * Import for real, then throw the uploads away.
     */
    public function confirm(): void
    {
        $this->finished = $this->run(dryRun: false);

        if ($this->finished === null) {
            return;
        }

        $this->previewed = null;
        $this->discard();

        Flux::toast(variant: 'success', text: trans_choice(
            '{1}Imported 1 invoice.|[2,*]Imported :count invoices.',
            $this->finished['imported'],
            ['count' => $this->finished['imported']],
        ));
    }

    /**
     * Throw the uploaded files away.
     *
     * A CSV of every client and every amount the studio has ever billed is not
     * something to leave lying about, and once it is imported the invoices table is
     * the record. Called on the way out whether the import worked or not.
     */
    public function discard(): void
    {
        $this->forget($this->invoices);
        $this->forget($this->payments);

        $this->reset(['invoices', 'payments']);
    }

    /**
     * Delete a temporary upload, and the sidecar Livewire wrote beside it.
     *
     * `TemporaryUploadedFile::delete()` removes only the file itself, leaving a
     * `<name>.json` holding the original filename and size behind. That is a
     * small leak on its own, but this screen tells the studio the uploaded
     * files have been deleted, and that has to be true.
     */
    private function forget(?TemporaryUploadedFile $file): void
    {
        if ($file === null) {
            return;
        }

        $path = FileUploadConfiguration::path($file->getFilename());

        Storage::disk(FileUploadConfiguration::disk())->delete([$path, $path.'.json']);
    }

    /**
     * Start again without importing anything.
     */
    public function cancel(): void
    {
        $this->reset(['previewed', 'finished']);
        $this->discard();
    }

    /**
     * Get the studio settings, which supply the payment terms an invoice with no due
     * date in the export falls back to.
     */
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Get the largest upload this server will actually take.
     */
    public function maxKb(): int
    {
        return Attachment::maxUploadKb(Attachment::STUDIO_MAX_KB);
    }

    /**
     * Validate the uploads and hand them to the importer.
     *
     * @return array<string, mixed>|null
     */
    private function run(bool $dryRun): ?array
    {
        $rules = Attachment::rules(['csv', 'txt'], $this->maxKb());

        $this->validate(
            [
                'invoices' => $rules,
                'payments' => Attachment::rules(['csv', 'txt'], $this->maxKb(), required: false),
            ],
            [
                ...Attachment::messages('invoices', ['csv', 'txt'], $this->maxKb()),
                ...Attachment::messages('payments', ['csv', 'txt'], $this->maxKb()),
                'invoices.required' => __('Choose the invoice export to import.'),
            ],
        );

        $action = new ImportInvoices($this->settings());

        try {
            $result = $action->handle(
                invoicesPath: $this->invoices->getRealPath(),
                paymentsPath: $this->payments?->getRealPath(),
                dryRun: $dryRun,
            );
        } catch (ImportException $e) {
            // Put the complaint under the upload it is about, rather than failing with
            // no clue which of the two files was the problem.
            $this->addError($e->field(), $e->getMessage());

            if (! $dryRun) {
                $this->discard();
            }

            return null;
        }

        $summary = $action->summarise($result);

        if ($summary['imported'] === 0 && $summary['skipped'] === 0) {
            $this->addError('invoices', __('That export has no invoices in it.'));

            return null;
        }

        return $summary;
    }
}; ?>

<div>
    <div class="mb-[clamp(20px,2.4vw,30px)]">
        <x-rsc.kicker class="mb-2.5">invoice_ninja</x-rsc.kicker>
        <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Import') }}</x-rsc.heading>
        <p class="mt-3 mb-0 max-w-[62ch] text-sm text-muted">
            {{ __('Bring invoice history across from Invoice Ninja. Nothing is written until you have seen what it would do, and the files are deleted the moment the import finishes.') }}
        </p>
    </div>

    @if ($finished)
        <x-rsc.panel class="mb-[clamp(12px,1.4vw,18px)]">
            <x-rsc.kicker tone="brand" class="mb-2.5">done</x-rsc.kicker>
            <x-rsc.heading :level="2" class="!text-[clamp(19px,2vw,25px)]">
                {{ trans_choice('{1}1 invoice imported|[2,*]:count invoices imported', $finished['imported'], ['count' => $finished['imported']]) }}
            </x-rsc.heading>

            <div class="mt-4 flex flex-wrap gap-x-7 gap-y-2 font-mono text-[11px] text-muted">
                @foreach ($finished['totals'] as $total)
                    <span>{{ $total['currency'] }} <span class="text-body">{{ $total['total'] }}</span></span>
                @endforeach
                <span>{{ __('payment dates filled in') }} <span class="text-body">{{ $finished['dated'] }}</span></span>
            </div>

            <p class="mt-4 mb-0 text-sm text-muted">
                {{ __('The uploaded files have been deleted.') }}
            </p>

            <div class="mt-5 flex flex-wrap gap-3">
                <x-rsc.button as="a" href="{{ route('admin.invoices') }}" wire:navigate>{{ __('See the invoices') }}</x-rsc.button>
                <x-rsc.button wire:click="cancel" variant="outline">{{ __('Import another export') }}</x-rsc.button>
            </div>
        </x-rsc.panel>
    @endif

    @if (! $previewed && ! $finished)
        <x-rsc.panel>
            <x-rsc.kicker class="mb-4">files</x-rsc.kicker>

            <div class="flex flex-col gap-3.5">
                <x-rsc.dropzone name="invoices" model="invoices"
                                :title="__('Invoice export')"
                                :hint="$invoices
                                    ? $invoices->getClientOriginalName()
                                    : __('The Invoice report from Invoice Ninja, as CSV. Up to :size.', ['size' => \Illuminate\Support\Number::fileSize($this->maxKb() * 1024)])" />

                <x-rsc.dropzone name="payments" model="payments"
                                :title="__('Payments export (optional)')"
                                :hint="$payments
                                    ? $payments->getClientOriginalName()
                                    : __('The Payment report — a different report type, and the only one carrying the date each invoice was settled. Skip it and dates stay blank; add it any time and re-run.')" />
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <x-rsc.button wire:click="preview" wire:loading.attr="disabled" wire:target="preview,invoices,payments">
                    <span wire:loading.remove wire:target="preview">{{ __('Show me what it would do') }}</span>
                    <span wire:loading wire:target="preview">{{ __('Reading…') }}</span>
                </x-rsc.button>
                <span class="font-mono text-[11px] text-muted">{{ __('nothing is written yet') }}</span>
            </div>
        </x-rsc.panel>
    @endif

    @if ($previewed)
        <x-rsc.panel>
            <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <x-rsc.kicker tone="warm" class="mb-2.5">preview</x-rsc.kicker>
                    <x-rsc.heading :level="2" class="!text-[clamp(19px,2vw,25px)]">
                        {{ trans_choice('{1}1 invoice to import|[2,*]:count invoices to import', $previewed['imported'], ['count' => $previewed['imported']]) }}
                    </x-rsc.heading>
                </div>

                <div class="text-end">
                    @foreach ($previewed['totals'] as $total)
                        <div class="font-display text-[clamp(19px,2vw,25px)] font-extrabold tracking-[-0.025em]">{{ $total['total'] }}</div>
                    @endforeach
                    <div class="mt-1 font-mono text-[11px] text-muted">{{ __('check this against Invoice Ninja') }}</div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[420px] rounded-[14px] border border-line">
                    <div class="grid grid-cols-[1fr_110px_130px] gap-4 border-b border-line px-[18px] py-3 font-mono text-[11px] tracking-[0.08em] text-muted">
                        <span>client</span><span>invoices</span><span>total</span>
                    </div>

                    @foreach ($previewed['clients'] as $client)
                        <div class="grid grid-cols-[1fr_110px_130px] items-center gap-4 border-b border-line px-[18px] py-3 last:border-b-0"
                             wire:key="preview-{{ $loop->index }}">
                            <span class="font-display text-sm font-bold">{{ $client['name'] }}</span>
                            <span class="text-[13px] text-muted">{{ $client['invoices'] }}</span>
                            <span class="font-display text-sm font-bold">{{ $client['total'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <dl class="mt-5 grid gap-x-7 gap-y-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                @if ($previewed['opened'] !== [])
                    <div>
                        <dt class="font-mono text-[11px] tracking-[0.08em] text-muted">{{ __('clients to open') }}</dt>
                        <dd class="mt-1 mb-0 text-sm">
                            {{ count($previewed['opened']) }}
                            <span class="text-muted">— {{ __('nobody will be emailed') }}</span>
                        </dd>
                        <dd class="mt-1 mb-0 text-xs text-muted">{{ implode(', ', $previewed['opened']) }}</dd>
                    </div>
                @endif

                @if ($previewed['skipped'] > 0)
                    <div>
                        <dt class="font-mono text-[11px] tracking-[0.08em] text-muted">{{ __('already here') }}</dt>
                        <dd class="mt-1 mb-0 text-sm">
                            {{ $previewed['skipped'] }}
                            <span class="text-muted">— {{ __('will be left alone') }}</span>
                        </dd>
                    </div>
                @endif

                <div>
                    <dt class="font-mono text-[11px] tracking-[0.08em] text-muted">{{ __('payment dates') }}</dt>
                    <dd class="mt-1 mb-0 text-sm">{{ $previewed['dated'] }}</dd>
                    @if ($previewed['undated'] > 0)
                        <dd class="mt-1 mb-0 text-xs text-warm">
                            {{ trans_choice(
                                '{1}1 paid invoice has no date. Add the Payment report and re-run to fill it in.|[2,*]:count paid invoices have no date. Add the Payment report and re-run to fill them in.',
                                $previewed['undated'],
                                ['count' => $previewed['undated']],
                            ) }}
                        </dd>
                    @endif
                </div>
            </dl>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-rsc.button wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">
                    <span wire:loading.remove wire:target="confirm">{{ __('Import these') }}</span>
                    <span wire:loading wire:target="confirm">{{ __('Importing…') }}</span>
                </x-rsc.button>
                <x-rsc.button wire:click="cancel" variant="outline" wire:loading.attr="disabled" wire:target="confirm">
                    {{ __('Cancel') }}
                </x-rsc.button>
            </div>
        </x-rsc.panel>
    @endif
</div>
