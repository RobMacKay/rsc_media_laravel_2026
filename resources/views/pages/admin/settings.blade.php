<?php

use App\Enums\Currency;
use App\Models\Plan;
use App\Models\StudioSetting;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Settings')]
class extends Component {
    /** @var array<string, mixed> */
    public array $studio = [];

    /** @var array<int, array<string, mixed>> */
    public array $plans = [];

    public ?int $clientId = null;

    /** @var array<string, mixed> */
    public array $client = [];

    /**
     * Load the studio defaults, plans and the first client's overrides.
     */
    public function mount(): void
    {
        $settings = StudioSetting::current();

        $this->studio = $settings->only([
            'company_name', 'company_number', 'address', 'email', 'phone', 'website',
            'hour_rate', 'day_rate', 'day_length', 'minimum_charge', 'out_of_hours_uplift',
            'payment_terms_days', 'late_fee_percent', 'vat_registered', 'vat_number', 'vat_rate',
            'account_name', 'bank_name', 'sort_code', 'account_number', 'reference_format',
        ]);

        $this->plans = Plan::query()->orderBy('sort_order')->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price' => $plan->price,
                'hours_per_month' => $plan->hours_per_month,
                'response_time' => $plan->response_time,
                'features' => implode("\n", $plan->features),
                'is_live' => $plan->is_live,
                'is_featured' => $plan->is_featured,
            ])
            ->all();

        $this->selectClient($this->clients->first()?->id);
    }

    /**
     * Get the clients whose rates can be overridden.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Team::query()->where('is_personal', false)->orderBy('name')->get();
    }

    /**
     * Get the currently selected client.
     */
    #[Computed]
    public function selectedClient(): ?Team
    {
        return $this->clients->firstWhere('id', $this->clientId);
    }

    /**
     * Get what each rate resolves to for the selected client once overrides apply.
     *
     * @return array<int, array{label: string, value: string, source: string}>
     */
    #[Computed]
    public function effective(): array
    {
        $override = fn (string $key, string $fallback) => filled($this->client[$key] ?? null)
            ? [$this->client[$key], 'set here']
            : [$this->studio[$fallback], 'default'];

        [$hour, $hourSource] = $override('hour_rate', 'hour_rate');
        [$day, $daySource] = $override('day_rate', 'day_rate');
        [$terms, $termsSource] = $override('payment_terms_days', 'payment_terms_days');

        $hours = $this->client['support_hours'] ?? null;
        $planHours = $this->selectedClient?->plan?->hours_per_month ?? 0;

        return [
            ['label' => 'currency', 'value' => $this->clientCurrency->label(), 'source' => 'set here'],
            ['label' => 'hour_rate', 'value' => $this->clientCurrency->format((float) $hour), 'source' => $hourSource],
            ['label' => 'day_rate', 'value' => $this->clientCurrency->format((float) $day), 'source' => $daySource],
            ['label' => 'support_hours', 'value' => rtrim(rtrim(number_format((float) (filled($hours) ? $hours : $planHours), 1), '0'), '.').'h', 'source' => filled($hours) ? 'set here' : 'from plan'],
            ['label' => 'payment_terms', 'value' => $terms.' days', 'source' => $termsSource],
        ];
    }

    /**
     * Get the rates this client is falling back to the studio default for
     * while being billed in another currency.
     *
     * The studio holds no exchange rates, so a default of 460 is charged as
     * 460 of the client's currency. That is a decision to make deliberately,
     * not to discover on an invoice.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function unconvertedRates(): array
    {
        if ($this->clientId === null || $this->clientCurrency === Currency::Base) {
            return [];
        }

        return collect(['hour_rate', 'day_rate'])
            ->reject(fn (string $key) => filled($this->client[$key] ?? null))
            ->values()
            ->all();
    }

    /**
     * Switch which client's overrides are being edited.
     */
    public function selectClient(?int $clientId): void
    {
        $this->clientId = $clientId;

        $team = $this->clients->firstWhere('id', $clientId);

        $this->client = [
            'currency' => $team?->currency->value,
            'hour_rate' => $team?->hour_rate,
            'day_rate' => $team?->day_rate,
            'support_hours' => $team?->support_hours,
            'payment_terms_days' => $team?->payment_terms_days,
            'purchase_order_ref' => $team?->purchase_order_ref,
            'billing_email' => $team?->billing_email,
        ];

        unset($this->selectedClient, $this->effective, $this->clientCurrency, $this->unconvertedRates);
    }

    /**
     * Set the currency this client is billed in.
     */
    public function chooseCurrency(string $currency): void
    {
        $this->client['currency'] = Currency::from($currency)->value;

        unset($this->effective, $this->clientCurrency, $this->unconvertedRates);
    }

    /**
     * Get the currency the selected client is billed in.
     */
    #[Computed]
    public function clientCurrency(): Currency
    {
        return Currency::tryFrom((string) ($this->client['currency'] ?? '')) ?? Currency::Base;
    }

    /**
     * Get readable names for the nested fields, so errors do not read "studio.day rate".
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'studio.company_name' => __('company name'),
            'studio.company_number' => __('company number'),
            'studio.address' => __('address'),
            'studio.email' => __('email'),
            'studio.phone' => __('phone'),
            'studio.website' => __('website'),
            'studio.hour_rate' => __('hourly rate'),
            'studio.day_rate' => __('day rate'),
            'studio.day_length' => __('hours in a day'),
            'studio.minimum_charge' => __('minimum charge'),
            'studio.out_of_hours_uplift' => __('out of hours uplift'),
            'studio.payment_terms_days' => __('payment terms'),
            'studio.late_fee_percent' => __('late fee'),
            'studio.vat_registered' => __('VAT registration'),
            'studio.vat_number' => __('VAT number'),
            'studio.vat_rate' => __('VAT rate'),
            'studio.account_name' => __('account name'),
            'studio.bank_name' => __('bank'),
            'studio.sort_code' => __('sort code'),
            'studio.account_number' => __('account number'),
            'studio.reference_format' => __('payment reference format'),
            'client.currency' => __('currency for this client'),
            'client.hour_rate' => __('hourly rate for this client'),
            'client.day_rate' => __('day rate for this client'),
            'client.support_hours' => __('support hours for this client'),
            'client.payment_terms_days' => __('payment terms for this client'),
            'client.purchase_order_ref' => __('purchase order reference'),
            'client.billing_email' => __('billing email'),
        ];
    }

    /**
     * Save the studio defaults, the plans and the selected client's overrides.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'studio.company_name' => ['required', 'string', 'max:255'],
            'studio.company_number' => ['nullable', 'string', 'max:255'],
            'studio.address' => ['nullable', 'string', 'max:1000'],
            'studio.email' => ['nullable', 'email', 'max:255'],
            'studio.phone' => ['nullable', 'string', 'max:255'],
            'studio.website' => ['nullable', 'string', 'max:255'],
            'studio.hour_rate' => ['required', 'integer', 'min:0'],
            'studio.day_rate' => ['required', 'integer', 'min:0'],
            'studio.day_length' => ['required', 'numeric', 'min:1', 'max:12'],
            'studio.minimum_charge' => ['required', 'numeric', 'min:0'],
            'studio.out_of_hours_uplift' => ['required', 'integer', 'min:0'],
            'studio.payment_terms_days' => ['required', 'integer', 'min:0'],
            'studio.late_fee_percent' => ['required', 'numeric', 'min:0'],
            'studio.vat_registered' => ['boolean'],
            'studio.vat_number' => ['nullable', 'string', 'max:255'],
            'studio.vat_rate' => ['required', 'numeric', 'min:0'],
            'studio.account_name' => ['nullable', 'string', 'max:255'],
            'studio.bank_name' => ['nullable', 'string', 'max:255'],
            'studio.sort_code' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}-\d{2}$/'],
            'studio.account_number' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'studio.reference_format' => ['required', 'string', 'max:255'],
            'plans.*.name' => ['required', 'string', 'max:255'],
            'plans.*.price' => ['required', 'integer', 'min:0'],
            'plans.*.hours_per_month' => ['required', 'numeric', 'min:0'],
            'plans.*.response_time' => ['required', 'string', 'max:255'],
            'plans.*.features' => ['nullable', 'string'],
            'client.currency' => ['nullable', Rule::enum(Currency::class)],
            'client.hour_rate' => ['nullable', 'integer', 'min:0'],
            'client.day_rate' => ['nullable', 'integer', 'min:0'],
            'client.support_hours' => ['nullable', 'numeric', 'min:0'],
            'client.payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'client.purchase_order_ref' => ['nullable', 'string', 'max:255'],
            'client.billing_email' => ['nullable', 'email', 'max:255'],
        ]);

        StudioSetting::current()->update($validated['studio']);

        foreach ($this->plans as $plan) {
            Plan::findOrFail($plan['id'])->update([
                'name' => $plan['name'],
                'price' => $plan['price'],
                'hours_per_month' => $plan['hours_per_month'],
                'response_time' => $plan['response_time'],
                'features' => collect(explode("\n", (string) $plan['features']))
                    ->map(fn (string $line) => trim($line))
                    ->filter()
                    ->values()
                    ->all(),
                'is_live' => (bool) $plan['is_live'],
                'is_featured' => (bool) $plan['is_featured'],
            ]);
        }

        $client = $validated['client'];

        if (blank($client['currency'] ?? null)) {
            unset($client['currency']);
        }

        $this->selectedClient?->update($client);

        unset($this->clients, $this->selectedClient, $this->effective, $this->clientCurrency, $this->unconvertedRates);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }
}; ?>

<div>
    <div class="mb-[clamp(20px,2.4vw,32px)]">
        <x-rsc.kicker class="mb-2.5">rsc_media</x-rsc.kicker>
        <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Settings') }}</x-rsc.heading>
        <p class="mt-3 max-w-[56ch] text-[15px] text-muted text-pretty">
            {{ __('These are your defaults. Anything set against a client below overrides them on that client\'s quotes and invoices.') }}
        </p>
    </div>

    <form wire:submit="save" class="flex flex-col gap-[clamp(12px,1.4vw,18px)]">
        <x-rsc.panel>
            <div class="mb-5 flex flex-wrap items-center gap-3 font-mono text-[11px] tracking-[0.08em] text-muted">
                <span>company_details</span>
                <span class="ms-auto text-[13px] normal-case tracking-normal">{{ __('These head every invoice.') }}</span>
            </div>

            <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
                <x-rsc.field label="company_name" name="studio.company_name">
                    <x-rsc.input wire:model="studio.company_name" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="company_number" name="studio.company_number">
                    <x-rsc.input wire:model="studio.company_number" placeholder="SC512347" class="!py-3 font-mono" />
                </x-rsc.field>
                <x-rsc.field label="website" name="studio.website">
                    <x-rsc.input wire:model="studio.website" placeholder="rscmedia.co.uk" class="!py-3" />
                </x-rsc.field>
            </div>

            <div class="mt-[18px] grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
                <x-rsc.field label="address" name="studio.address" hint="{{ __('one line each') }}">
                    <x-rsc.textarea wire:model="studio.address" rows="4" class="!py-3 !text-sm">{{ $studio['address'] ?? '' }}</x-rsc.textarea>
                </x-rsc.field>
                <div class="flex flex-col gap-[18px]">
                    <x-rsc.field label="email" name="studio.email">
                        <x-rsc.input type="email" wire:model="studio.email" class="!py-3" />
                    </x-rsc.field>
                    <x-rsc.field label="phone" name="studio.phone">
                        <x-rsc.input wire:model="studio.phone" class="!py-3" />
                    </x-rsc.field>
                </div>
            </div>
        </x-rsc.panel>

        <x-rsc.panel>
            <div class="mb-5 flex flex-wrap items-center gap-3 font-mono text-[11px] tracking-[0.08em] text-muted">
                <span>default_rates</span>
                <span class="ms-auto text-brand">
                    {{ __('a day at the hourly rate is :total', ['total' => Currency::Base->format(round(($studio['hour_rate'] ?? 0) * ($studio['day_length'] ?? 0)))]) }}
                </span>
            </div>

            <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                <x-rsc.field label="hour_rate_{{ Currency::Base->symbol() }}" name="studio.hour_rate">
                    <x-rsc.input type="number" min="0" step="5" wire:model.live="studio.hour_rate" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="day_rate_{{ Currency::Base->symbol() }}" name="studio.day_rate">
                    <x-rsc.input type="number" min="0" step="10" wire:model="studio.day_rate" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="hours_in_a_day" name="studio.day_length">
                    <x-rsc.input type="number" min="1" max="12" step="0.5" wire:model.live="studio.day_length" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="minimum_charge_h" name="studio.minimum_charge">
                    <x-rsc.input type="number" min="0" step="0.25" wire:model="studio.minimum_charge" class="!py-3" />
                </x-rsc.field>
            </div>

            <div class="mt-[18px] grid gap-[18px] border-t border-line pt-5 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                <x-rsc.field label="out_of_hours_uplift_%" name="studio.out_of_hours_uplift">
                    <x-rsc.input type="number" min="0" step="5" wire:model="studio.out_of_hours_uplift" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="payment_terms_days" name="studio.payment_terms_days">
                    <x-rsc.input type="number" min="0" step="1" wire:model.live="studio.payment_terms_days" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="late_fee_%_per_month" name="studio.late_fee_percent">
                    <x-rsc.input type="number" min="0" step="0.5" wire:model="studio.late_fee_percent" class="!py-3" />
                </x-rsc.field>
            </div>
        </x-rsc.panel>

        <x-rsc.panel>
            <div class="mb-5 font-mono text-[11px] tracking-[0.08em] text-muted">vat</div>
            <div class="grid items-end gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
                <label class="flex cursor-pointer items-center gap-2.5 pb-3 text-sm text-muted">
                    <input type="checkbox" wire:model.live="studio.vat_registered" class="size-4" style="accent-color: var(--rsc-accent)">
                    <span>{{ __('VAT registered') }}</span>
                </label>
                <x-rsc.field label="vat_number" name="studio.vat_number">
                    <x-rsc.input wire:model="studio.vat_number" placeholder="GB123456789" class="!py-3 font-mono tracking-[0.04em]" />
                </x-rsc.field>
                <x-rsc.field label="vat_rate_%" name="studio.vat_rate">
                    <x-rsc.input type="number" min="0" step="0.5" wire:model.live="studio.vat_rate" class="!py-3" />
                </x-rsc.field>
            </div>
            <p class="mt-4 text-[13px] text-muted text-pretty">
                {{ ($studio['vat_registered'] ?? false)
                    ? __('Invoices add :rate% VAT and show your VAT number.', ['rate' => rtrim(rtrim(number_format((float) ($studio['vat_rate'] ?? 0), 1), '0'), '.')])
                    : __('Invoices go out with no VAT line at all.') }}
            </p>
        </x-rsc.panel>

        <x-rsc.panel>
            <div class="mb-5 font-mono text-[11px] tracking-[0.08em] text-muted">bank_details</div>
            <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(210px,1fr))]">
                <x-rsc.field label="account_name" name="studio.account_name">
                    <x-rsc.input wire:model="studio.account_name" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="bank" name="studio.bank_name">
                    <x-rsc.input wire:model="studio.bank_name" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="sort_code" name="studio.sort_code">
                    <x-rsc.input wire:model="studio.sort_code" inputmode="numeric" placeholder="00-00-00" class="!py-3 font-mono tracking-[0.08em]" />
                </x-rsc.field>
                <x-rsc.field label="account_number" name="studio.account_number">
                    <x-rsc.input wire:model="studio.account_number" inputmode="numeric" placeholder="12345678" class="!py-3 font-mono tracking-[0.08em]" />
                </x-rsc.field>
            </div>

            <div class="mt-[18px] grid gap-[18px] border-t border-line pt-5 [grid-template-columns:repeat(auto-fit,minmax(210px,1fr))]">
                <x-rsc.field label="payment_reference_format" name="studio.reference_format">
                    <x-rsc.input wire:model.live="studio.reference_format" class="!py-3 font-mono" />
                </x-rsc.field>
                <div>
                    <div class="mb-2 font-mono text-[11px] text-muted">example_on_invoice</div>
                    <div class="py-3 font-display text-[17px] font-bold tracking-[-0.01em]">
                        {{ str($studio['reference_format'] ?? '')->replace('{invoice}', '0148') }}
                    </div>
                </div>
            </div>
        </x-rsc.panel>

        <x-rsc.panel>
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <span class="font-mono text-[11px] tracking-[0.08em] text-muted">support_plans</span>
                <span class="ms-auto text-[13px] text-muted">{{ __('Shown to clients in their portal when they pick a plan.') }}</span>
            </div>

            <div class="flex flex-col gap-3.5">
                @foreach ($plans as $index => $plan)
                    <div class="rounded-2xl border p-[clamp(16px,1.8vw,22px)] {{ $plan['is_featured'] ? 'border-brand' : 'border-line' }}" wire:key="plan-{{ $plan['id'] }}">
                        <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(140px,1fr))]">
                            <x-rsc.field label="{{ $plan['slug'] }}_name" name="plans.{{ $index }}.name">
                                <x-rsc.input wire:model="plans.{{ $index }}.name" class="!py-[11px] font-display font-bold" />
                            </x-rsc.field>
                            <x-rsc.field label="price_pm_{{ Currency::Base->symbol() }}" name="plans.{{ $index }}.price">
                                <x-rsc.input type="number" min="0" step="5" wire:model.live="plans.{{ $index }}.price" class="!py-[11px]" />
                            </x-rsc.field>
                            <x-rsc.field label="hours_pm" name="plans.{{ $index }}.hours_per_month">
                                <x-rsc.input type="number" min="0" step="0.5" wire:model.live="plans.{{ $index }}.hours_per_month" class="!py-[11px]" />
                            </x-rsc.field>
                            <x-rsc.field label="response" name="plans.{{ $index }}.response_time">
                                <x-rsc.select wire:model="plans.{{ $index }}.response_time" class="!py-[11px] !text-sm">
                                    @foreach (['same working day', 'within 1 working day', 'next working day', 'within 3 working days'] as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </x-rsc.select>
                            </x-rsc.field>
                        </div>

                        <div class="mt-4">
                            <x-rsc.field label="what_it_covers" name="plans.{{ $index }}.features"
                                         hint="{{ __('one line per bullet') }}">
                                <x-rsc.textarea wire:model="plans.{{ $index }}.features" rows="4" class="!py-[11px] !text-sm">{{ $plan['features'] }}</x-rsc.textarea>
                            </x-rsc.field>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-line pt-3.5">
                            <label class="flex cursor-pointer items-center gap-2.5 text-[13px] text-muted">
                                <input type="checkbox" wire:model="plans.{{ $index }}.is_live" class="size-4" style="accent-color: var(--rsc-accent)">
                                <span>{{ __('Offer this plan') }}</span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2.5 text-[13px] text-muted">
                                <input type="checkbox" wire:model.live="plans.{{ $index }}.is_featured" class="size-4" style="accent-color: var(--rsc-accent)">
                                <span>{{ __('Mark as most chosen') }}</span>
                            </label>
                            <span class="ms-auto font-mono text-[11px] text-muted">
                                {{ ($plan['hours_per_month'] ?? 0) > 0
                                    ? __(':rate an hour once used up', ['rate' => Currency::Base->format(round($plan['price'] / max($plan['hours_per_month'], 1)))])
                                    : __('no hours included') }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-rsc.panel>

        <x-rsc.panel>
            <div class="mb-[18px] flex flex-wrap items-center gap-3">
                <span class="font-mono text-[11px] tracking-[0.08em] text-muted">per_client_overrides</span>
                <span class="ms-auto text-[13px] text-muted">{{ __('Leave a field blank to use the default.') }}</span>
            </div>

            <div class="mb-5 flex flex-wrap gap-2">
                @foreach ($this->clients as $team)
                    <x-rsc.chip wire:click="selectClient({{ $team->id }})" :active="$clientId === $team->id" class="!text-[13px] !font-sans">
                        {{ $team->name }}
                    </x-rsc.chip>
                @endforeach
            </div>

            <div class="mb-[18px]">
                <div class="mb-2.5 font-mono text-[11px] tracking-[0.08em] text-muted">currency</div>
                <div class="flex flex-wrap gap-2">
                    @foreach (Currency::cases() as $option)
                        <x-rsc.chip wire:click="chooseCurrency('{{ $option->value }}')"
                                    :active="$this->clientCurrency === $option"
                                    :disabled="$clientId === null"
                                    class="!text-[13px] !font-sans disabled:cursor-not-allowed disabled:opacity-45">
                            {{ $option->label() }}
                        </x-rsc.chip>
                    @endforeach
                </div>
                <p class="m-0 mt-2.5 text-[13px] text-muted">
                    {{ __('Everything this client is quoted and invoiced is in this currency. Invoices already raised keep the currency they were raised in.') }}
                </p>
                @error('client.currency') <p class="m-0 mt-2 text-[13px] text-warm">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                <x-rsc.field label="hour_rate_{{ $this->clientCurrency->symbol() }}" name="client.hour_rate">
                    <x-rsc.input type="number" min="0" step="5" wire:model.live="client.hour_rate" placeholder="{{ $studio['hour_rate'] ?? '' }}" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="day_rate_{{ $this->clientCurrency->symbol() }}" name="client.day_rate">
                    <x-rsc.input type="number" min="0" step="10" wire:model.live="client.day_rate" placeholder="{{ $studio['day_rate'] ?? '' }}" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="support_hours_pm" name="client.support_hours">
                    <x-rsc.input type="number" min="0" step="0.5" wire:model.live="client.support_hours" placeholder="0" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="payment_terms_days" name="client.payment_terms_days">
                    <x-rsc.input type="number" min="0" step="1" wire:model.live="client.payment_terms_days" placeholder="{{ $studio['payment_terms_days'] ?? '' }}" class="!py-3" />
                </x-rsc.field>
            </div>

            <div class="mt-[18px] grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(210px,1fr))]">
                <x-rsc.field label="purchase_order_ref" name="client.purchase_order_ref">
                    <x-rsc.input wire:model="client.purchase_order_ref" placeholder="{{ __('Not required') }}" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="billing_email" name="client.billing_email">
                    <x-rsc.input type="email" wire:model="client.billing_email" class="!py-3" />
                </x-rsc.field>
            </div>

            <div class="mt-5 border-t border-line pt-5">
                <div class="mb-2.5 font-mono text-[11px] text-muted">
                    in_effect_for_{{ str($this->selectedClient?->name ?? '')->snake()->lower() }}
                </div>
                <div class="flex flex-wrap gap-x-7 gap-y-2.5">
                    @foreach ($this->effective as $row)
                        <span class="flex flex-col gap-1.5">
                            <span class="font-mono text-[11px] text-muted">{{ $row['label'] }}</span>
                            <span class="flex items-baseline gap-2">
                                <span class="font-display text-[19px] font-bold tracking-[-0.02em]">{{ $row['value'] }}</span>
                                <span class="font-mono text-[10px] {{ $row['source'] === 'default' ? 'text-muted' : 'text-brand' }}">{{ $row['source'] }}</span>
                            </span>
                        </span>
                    @endforeach
                </div>

                @if ($this->unconvertedRates)
                    <p class="m-0 mt-4 border-t border-line pt-4 text-[13px] text-warm">
                        {{ trans_choice(
                            '{1}:rates is the studio default, and nothing converts it — :amount is charged as :converted. Set one here if that is not what you mean.'
                            .'|[2,*]:rates are the studio defaults, and nothing converts them — :amount is charged as :converted. Set them here if that is not what you mean.',
                            count($this->unconvertedRates),
                            [
                            'rates' => str(collect($this->unconvertedRates)->map(fn (string $rate) => str_replace('_', ' ', $rate))->join(' and '))->ucfirst()->toString(),
                            'amount' => Currency::Base->format((float) ($studio[$this->unconvertedRates[0]] ?? 0)),
                            'converted' => $this->clientCurrency->format((float) ($studio[$this->unconvertedRates[0]] ?? 0)),
                            ],
                        ) }}
                    </p>
                @endif
            </div>
        </x-rsc.panel>

        <div class="flex flex-wrap items-center gap-3.5">
            <x-rsc.button type="submit" class="px-7 py-3.5">{{ __('Save settings') }}</x-rsc.button>
            <span class="text-[13px] text-muted">{{ __('Rates apply to new quotes only. Quotes already sent keep the price you quoted.') }}</span>
        </div>
    </form>
</div>
