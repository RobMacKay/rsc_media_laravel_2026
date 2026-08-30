<?php

use App\Models\Enquiry;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.marketing')]
#[Title('Software that keeps up')]
class extends Component {
    public string $name = '';

    public string $email = '';

    public string $company = '';

    public string $topic = 'app';

    public string $message = '';

    public bool $sent = false;

    /**
     * Get the support plans advertised on the public site.
     *
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->offered()->get();
    }

    /**
     * Record the enquiry and let the studio know about it.
     */
    public function send(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'topic' => ['required', Rule::in(array_keys(Enquiry::TOPICS))],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $enquiry = Enquiry::create($validated);

        Notification::send(User::query()->where('is_admin', true)->get(), new \App\Notifications\NewEnquiry($enquiry));

        $this->reset('name', 'email', 'company', 'message');
        $this->sent = true;
    }
}; ?>

@php
    $services = [
        ['01', 'Custom web applications', 'Dashboards, portals, booking, quoting — built around how you actually work.'],
        ['02', 'Mobile apps & PWAs', 'Installable and offline-capable. As good in a van as on a desk.'],
        ['03', 'Business & management software', 'The spreadsheet that grew too big, rebuilt as something reliable.'],
        ['04', 'Architecture & integration', 'APIs, data flows and migrations, so nobody retypes numbers between systems.'],
        ['05', 'Websites & ecommerce', 'Fast, findable, easy to edit. WordPress when it fits, custom when it doesn\'t.'],
        ['06', 'Consultancy & advisory', 'A second opinion before you commit — including when the answer is don\'t build it.'],
    ];

    $reasons = [
        ['a', 'You talk to the builder', 'No account managers relaying messages.'],
        ['b', 'Fits you, not a template', 'We build around how you already work.'],
        ['c', 'Built to grow', 'The next feature is a conversation, not a rebuild.'],
        ['d', 'Your team is trained', 'So the new tool gets used, not quietly avoided.'],
        ['e', 'Clear about cost', 'Scoped in stages, prices agreed up front.'],
        ['f', 'We don\'t disappear', 'Hosting, updates and small changes, ongoing.'],
        ['g', 'You own it', 'Code, hosting and accounts stay in your name.'],
        ['h', 'Documented handover', 'You get the documentation, not just a zip file.'],
    ];

    $steps = [
        ['01', 'Sign up with an email address', 'No card, no contract, no sales call unless you ask for one.', false],
        ['02', 'Describe the job in plain English', 'A few sentences is enough. Attach anything you\'ve already got.', false],
        ['03', 'Get a written estimate back', 'Hours, cost and a realistic timescale, usually within a working day.', false],
        ['04', 'Walk away or say yes', 'The estimate is yours either way. Nothing starts until you approve it.', true],
    ];

    $marquee = ['Web applications', 'PWAs', 'Integrations', 'Internal tools', 'Ecommerce', 'Hosting', 'Support'];

    $navLinks = [
        ['#build', 'what_we_build'],
        ['#why', 'why_us'],
        ['#support', 'support'],
        ['#about', 'about'],
    ];

    // Signed-in visitors get a way back into their portal instead of a login
    // link, which would only bounce them off the guest middleware.
    $portal = auth()->check()
        ? ['url' => route(auth()->user()->portalRoute()), 'label' => auth()->user()->is_admin ? 'admin' : 'client_area']
        : ['url' => route('login'), 'label' => 'client_login'];

    $mobileLinks = collect($navLinks)
        ->map(fn (array $link) => ['url' => $link[0], 'label' => $link[1]])
        ->push($portal)
        ->all();
@endphp

<div>
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 z-0 opacity-45 rsc-hatch"></div>
    <div aria-hidden="true"
         class="pointer-events-none fixed left-1/2 top-[-30vh] z-0 h-[110vh] w-[120vw] -translate-x-1/2 animate-rsc-drift blur-[20px]"
         style="background: radial-gradient(48% 42% at 50% 40%, var(--rsc-glow), transparent 70%)"></div>

    <div class="relative z-1">
        <header class="sticky top-0 z-40 flex flex-wrap items-center gap-x-7 gap-y-3 border-b border-line px-[clamp(18px,5vw,64px)] py-4 backdrop-blur-[14px]"
                style="background: color-mix(in srgb, var(--rsc-ink) 82%, transparent)">
            <a href="#top" aria-label="RSC Media" class="me-auto">
                <x-rsc.logo class="h-6 w-auto" />
            </a>

            <nav class="hidden flex-wrap items-center gap-x-6 gap-y-2 font-mono text-xs tracking-[0.04em] lg:flex">
                @foreach ($navLinks as [$href, $label])
                    <a href="{{ $href }}" class="text-muted no-underline transition-colors duration-200 hover:text-body">{{ $label }}</a>
                @endforeach

                <a href="{{ $portal['url'] }}" class="text-muted no-underline transition-colors duration-200 hover:text-body">{{ $portal['label'] }}</a>

                <x-rsc.theme-toggle />

                <a href="#contact"
                   class="inline-flex items-center gap-2 rounded-full bg-brand px-4 py-2.5 text-[13px] font-semibold text-accent-ink no-underline transition-transform duration-200 hover:-translate-y-0.5">
                    {{ __('Start a conversation') }}
                </a>
            </nav>

            <x-rsc.mobile-nav :links="$mobileLinks">
                <x-slot:footer>
                    <a href="#contact"
                       x-on:click="close()"
                       class="inline-flex items-center justify-center rounded-full bg-brand px-4 py-3 text-[13px] font-semibold text-accent-ink no-underline">
                        {{ __('Start a conversation') }}
                    </a>

                    <x-rsc.theme-toggle class="self-start" />
                </x-slot:footer>
            </x-rsc.mobile-nav>
        </header>

        <section id="top" class="px-[clamp(18px,5vw,64px)] pt-[clamp(48px,9vw,132px)]">
            <div class="mb-[clamp(22px,3vw,38px)] flex items-center gap-3 font-mono text-xs tracking-[0.1em] text-muted">
                <span class="block h-px w-[30px] bg-brand"></span>
                <span>{{ __('product & application studio') }}</span>
                <span class="text-warm">/</span>
                <span>{{ __('scotland') }}</span>
            </div>

            <h1 class="m-0 mb-[clamp(28px,3.4vw,48px)] font-display font-extrabold tracking-[-0.04em]">
                <span class="block text-[clamp(19px,2.2vw,32px)] font-medium leading-tight tracking-[-0.02em] text-muted">{{ __('Your business already works.') }}</span>
                <span class="mt-[0.14em] block bg-clip-text text-[clamp(38px,7.6vw,116px)] leading-[0.94] text-transparent"
                      style="background-image: linear-gradient(96deg, var(--rsc-accent), var(--rsc-accent-soft))">{{ __('We build the software') }}</span>
                <span class="block text-[clamp(38px,7.6vw,116px)] leading-[0.94]">{{ __('that keeps up.') }}</span>
            </h1>

            <div class="flex flex-wrap items-end justify-between gap-[clamp(20px,3vw,52px)]">
                <p class="m-0 max-w-[42ch] text-[clamp(16px,1.5vw,19px)] leading-relaxed text-muted text-pretty">
                    {{ __('Web applications, PWAs and the systems behind them. We design the architecture, write the code, and stay on afterwards.') }}
                </p>
                <div class="flex flex-wrap gap-2.5">
                    <x-rsc.button as="a" href="#contact" class="!px-6 !py-[15px]">{{ __('Tell us what you need') }}</x-rsc.button>
                    <x-rsc.button as="a" href="https://wa.me/447522375848" variant="outline" class="!px-6 !py-[15px]">{{ __('WhatsApp') }}</x-rsc.button>
                </div>
            </div>

            <div class="mt-[clamp(36px,5vw,72px)] grid gap-[clamp(16px,2vw,28px)] [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">
                <div class="relative overflow-hidden rounded-[20px] border border-line bg-panel p-[clamp(18px,2.2vw,28px)]">
                    <div class="mb-[18px] flex items-center justify-between font-mono text-[11px] tracking-[0.1em] text-muted">
                        <span>system.architecture</span>
                        <span class="flex items-center gap-[7px]">
                            <span class="size-[7px] rounded-full" style="background: var(--rsc-warm); box-shadow: 0 0 10px var(--rsc-warm)"></span>live
                        </span>
                    </div>

                    <svg viewBox="0 0 520 220" role="img"
                         aria-label="{{ __('Diagram: clients and devices connect through an API layer to services and data') }}"
                         class="block h-auto w-full">
                        <g fill="none" stroke="var(--rsc-line)" stroke-width="1">
                            <rect x="12" y="24" width="104" height="40" rx="8"></rect>
                            <rect x="12" y="90" width="104" height="40" rx="8"></rect>
                            <rect x="12" y="156" width="104" height="40" rx="8"></rect>
                            <rect x="208" y="80" width="104" height="60" rx="10" stroke="var(--rsc-accent)"></rect>
                            <rect x="404" y="24" width="104" height="40" rx="8"></rect>
                            <rect x="404" y="90" width="104" height="40" rx="8"></rect>
                            <rect x="404" y="156" width="104" height="40" rx="8"></rect>
                        </g>
                        <g fill="var(--rsc-muted)" font-family="JetBrains Mono, monospace" font-size="11">
                            <text x="30" y="49">browser</text>
                            <text x="30" y="115">mobile</text>
                            <text x="30" y="181">field app</text>
                            <text x="424" y="49">services</text>
                            <text x="424" y="115">database</text>
                            <text x="424" y="181">3rd party</text>
                        </g>
                        <text x="232" y="115" fill="var(--rsc-accent)" font-family="JetBrains Mono, monospace" font-size="12">api layer</text>
                        <g fill="none" stroke="var(--rsc-accent)" stroke-width="1.4" stroke-dasharray="6 10" opacity="0.85">
                            <path d="M116 44 C 165 44, 170 100, 208 106" style="animation: rsc-flow 3.4s linear infinite"></path>
                            <path d="M116 110 L 208 110" style="animation: rsc-flow 3.4s linear infinite 0.4s"></path>
                            <path d="M116 176 C 165 176, 170 120, 208 114" style="animation: rsc-flow 3.4s linear infinite 0.8s"></path>
                            <path d="M312 106 C 350 100, 355 44, 404 44" style="animation: rsc-flow 3.4s linear infinite 0.2s"></path>
                            <path d="M312 110 L 404 110" style="animation: rsc-flow 3.4s linear infinite 0.6s"></path>
                            <path d="M312 114 C 350 120, 355 176, 404 176" style="animation: rsc-flow 3.4s linear infinite 1s"></path>
                        </g>
                        <g fill="var(--rsc-accent)">
                            <circle cx="208" cy="106" r="3" style="animation: rsc-pulse 2.2s ease-in-out infinite"></circle>
                            <circle cx="312" cy="110" r="3" style="animation: rsc-pulse 2.2s ease-in-out infinite 0.7s"></circle>
                            <circle cx="208" cy="114" r="3" style="animation: rsc-pulse 2.2s ease-in-out infinite 1.4s"></circle>
                        </g>
                    </svg>
                </div>

                <div class="relative min-h-[240px] overflow-hidden rounded-[20px] border border-line bg-panel">
                    <img src="{{ asset('images/hero.webp') }}" alt="" class="absolute inset-0 size-full object-cover" loading="lazy">
                </div>
            </div>

            <div class="mt-[clamp(36px,5vw,72px)] grid gap-px overflow-hidden rounded-2xl border border-line [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]"
                 style="background: var(--rsc-line)">
                @foreach ([
                    ['Architecture first', 'Structure before pixels'],
                    ['Built to be kept', 'Documented, handed over properly'],
                    ['One team', 'Whoever scopes it, builds it'],
                    ['Still here after', 'Support for as long as you need'],
                ] as [$title, $body])
                    <div class="bg-ink p-[clamp(18px,2vw,26px)]">
                        <div class="font-display text-[clamp(19px,1.9vw,26px)] font-bold tracking-[-0.02em]">{{ $title }}</div>
                        <div class="mt-1.5 text-[13px] text-muted">{{ $body }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="mt-[clamp(52px,7vw,100px)] -ms-[2vw] w-[104vw] -rotate-[1.2deg] overflow-hidden bg-brand py-4 text-accent-ink">
            <div class="flex w-max animate-rsc-marquee font-display text-[clamp(22px,2.8vw,40px)] font-extrabold tracking-[-0.03em]">
                @foreach ([false, true] as $duplicate)
                    <span class="flex gap-[26px] whitespace-nowrap pe-[26px]" @if ($duplicate) aria-hidden="true" @endif>
                        @foreach ($marquee as $word)
                            <span>{{ $word }}</span><span class="opacity-45">✳</span>
                        @endforeach
                    </span>
                @endforeach
            </div>
        </div>

        <section id="build" class="px-[clamp(18px,5vw,64px)] py-[clamp(56px,8vw,120px)]">
            <div class="mb-[clamp(28px,4vw,56px)] flex flex-wrap items-end justify-between gap-6">
                <div>
                    <x-rsc.kicker class="mb-3.5 !tracking-[0.1em] !text-xs">01 / what_we_build</x-rsc.kicker>
                    <h2 class="m-0 max-w-[16ch] font-display text-[clamp(30px,5vw,72px)] font-extrabold leading-none tracking-[-0.04em]">
                        {{ __('Software, and the structure underneath it') }}
                    </h2>
                </div>
                <p class="m-0 max-w-[32ch] text-[15px] text-muted text-pretty">
                    {{ __('Most of what we make doesn\'t look like a website. It looks like the thing your team opens every morning.') }}
                </p>
            </div>

            <div class="border-t border-line">
                @foreach ($services as [$number, $title, $body])
                    <a href="#contact"
                       class="group flex flex-wrap items-center gap-x-7 gap-y-2 border-b border-line px-[clamp(14px,2vw,28px)] py-[clamp(20px,2.6vw,34px)] text-body no-underline transition-all duration-500 hover:ps-[clamp(26px,3.6vw,56px)] hover:text-accent-ink"
                       style="background-image: linear-gradient(var(--rsc-accent), var(--rsc-accent)); background-repeat: no-repeat; background-size: 0% 100%"
                       onmouseover="this.style.backgroundSize='100% 100%'" onmouseout="this.style.backgroundSize='0% 100%'">
                        <span class="w-[26px] font-mono text-xs opacity-50">{{ $number }}</span>
                        <span class="flex-[1_1_320px] font-display text-[clamp(22px,3.2vw,44px)] font-bold tracking-[-0.035em]">{{ $title }}</span>
                        <span class="max-w-[34ch] flex-[1_1_240px] text-sm opacity-70">{{ $body }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="relative h-[clamp(240px,40vw,520px)] w-full overflow-hidden border-y border-line bg-panel">
            <img src="{{ asset('images/band.webp') }}" alt="" class="absolute inset-0 size-full object-cover" loading="lazy">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0"
                 style="background: linear-gradient(180deg, var(--rsc-ink), transparent 28%, transparent 72%, var(--rsc-ink))"></div>
        </div>

        <section id="why" class="px-[clamp(18px,5vw,64px)] py-[clamp(56px,8vw,120px)]">
            <x-rsc.kicker class="mb-3.5 !tracking-[0.1em] !text-xs">02 / why_us</x-rsc.kicker>
            <h2 class="m-0 mb-[clamp(30px,4vw,56px)] max-w-[18ch] font-display text-[clamp(30px,5vw,72px)] font-extrabold leading-none tracking-[-0.04em]">
                {{ __('Small enough to care, structured enough to deliver') }}
            </h2>

            <div class="grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(250px,1fr))]">
                @foreach ($reasons as [$mark, $title, $body])
                    <div class="rounded-2xl border border-line bg-ink p-[clamp(22px,2.6vw,34px)]">
                        <div class="mb-4 font-mono text-[11px] text-brand">{{ $mark }}</div>
                        <h4 class="m-0 mb-2 font-display text-[19px] font-bold tracking-[-0.02em]">{{ $title }}</h4>
                        <p class="m-0 text-sm text-muted">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="support" class="px-[clamp(18px,5vw,64px)] py-[clamp(56px,8vw,120px)]">
            <div class="mb-[clamp(28px,4vw,56px)] flex flex-wrap items-end justify-between gap-6">
                <div>
                    <x-rsc.kicker class="mb-3.5 !tracking-[0.1em] !text-xs">03 / support</x-rsc.kicker>
                    <h2 class="m-0 max-w-[16ch] font-display text-[clamp(30px,5vw,72px)] font-extrabold leading-none tracking-[-0.04em]">
                        {{ __('Someone on the other end of it') }}
                    </h2>
                </div>
                <p class="m-0 max-w-[32ch] text-[15px] text-muted">{{ __('Move between plans whenever it suits. No long tie-ins.') }}</p>
            </div>

            <div class="grid gap-[clamp(14px,1.6vw,22px)] [grid-template-columns:repeat(auto-fit,minmax(280px,1fr))]">
                @foreach ($this->plans as $plan)
                    <div class="rounded-[20px] border bg-panel p-[clamp(24px,2.8vw,36px)] transition-colors duration-200 {{ $plan->is_featured ? 'border-brand' : 'border-line hover:border-brand' }}"
                         @style(['box-shadow: 0 0 60px var(--rsc-glow)' => $plan->is_featured])>
                        <div class="mb-5 flex items-center justify-between">
                            <div class="font-mono text-[11px] tracking-[0.1em] text-muted">{{ $plan->slug }}</div>
                            @if ($plan->is_featured)
                                <span class="rounded-full bg-brand px-2.5 py-1 font-mono text-[10px] tracking-[0.08em] text-accent-ink">{{ __('most chosen') }}</span>
                            @endif
                        </div>
                        <h3 class="m-0 mb-1.5 font-display text-[28px] font-extrabold tracking-[-0.03em]">{{ $plan->name }}</h3>
                        <p class="m-0 mb-6 text-sm text-muted">{{ $plan->blurb }}</p>

                        <div class="flex flex-col gap-[11px] text-sm">
                            @foreach ($plan->features as $feature)
                                <div class="flex gap-[11px]"><span class="text-brand">→</span><span>{{ $feature }}</span></div>
                            @endforeach
                        </div>

                        <a href="#contact"
                           class="mt-[30px] block rounded-full px-[18px] py-[13px] text-center text-sm font-semibold no-underline transition-all duration-200 {{ $plan->is_featured ? 'bg-brand text-accent-ink hover:-translate-y-0.5' : 'border border-line text-body hover:border-brand hover:rsc-tint' }}">
                            {{ __('Ask about :plan', ['plan' => $plan->name]) }}
                        </a>
                    </div>
                @endforeach
            </div>

            <p class="mt-[22px] font-mono text-xs text-muted">{{ __('// pricing depends on what we\'re looking after — tell us what you\'ve got') }}</p>
        </section>

        <section id="about" class="px-[clamp(18px,5vw,64px)] py-[clamp(56px,8vw,120px)]">
            <div class="flex flex-wrap gap-[clamp(28px,4vw,64px)]">
                <div class="min-w-[min(100%,300px)] flex-[1_1_420px]">
                    <x-rsc.kicker class="mb-3.5 !tracking-[0.1em] !text-xs">04 / about</x-rsc.kicker>
                    <h2 class="m-0 mb-[26px] font-display text-[clamp(30px,5vw,72px)] font-extrabold leading-none tracking-[-0.04em]">{{ __('RSC Media Ltd') }}</h2>
                    <p class="max-w-[46ch] text-[17px] leading-relaxed text-muted text-pretty">
                        {{ __('A Scottish studio working with small and medium businesses on the software side. Sometimes that\'s a website. Usually it\'s the application behind it — the bit that takes bookings, tracks jobs and saves someone three hours a week.') }}
                    </p>
                    <p class="max-w-[46ch] text-[17px] leading-relaxed text-muted text-pretty">
                        {{ __('Straightforward conversations, honest estimates. If you don\'t need what you\'re asking for, we\'ll say so.') }}
                    </p>

                    <div class="mt-[38px] grid gap-5 border-t border-line pt-[26px] [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                        @foreach ([
                            ['Based in Scotland', 'Working UK-wide'],
                            ['Direct contact', 'Email, phone or WhatsApp'],
                            ['Client portal', 'Tickets and progress in one place'],
                        ] as [$title, $body])
                            <div>
                                <div class="font-display text-base font-bold">{{ $title }}</div>
                                <div class="mt-[3px] text-[13px] text-muted">{{ $body }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="min-w-[min(100%,280px)] flex-[1_1_320px]">
                    <div class="relative aspect-3/4 w-full overflow-hidden rounded-[20px] border border-line bg-panel">
                        <img src="{{ asset('images/about.webp') }}" alt="" class="absolute inset-0 size-full object-cover" loading="lazy">
                    </div>
                </div>
            </div>
        </section>

        <section id="free-account" class="px-[clamp(18px,5vw,64px)] py-[clamp(56px,8vw,120px)]">
            <div class="rounded-3xl border border-brand bg-panel p-[clamp(26px,4vw,60px)]" style="box-shadow: 0 0 70px var(--rsc-glow)">
                <div class="grid items-start gap-[clamp(26px,4vw,60px)] [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">
                    <div>
                        <div class="mb-4 flex items-center gap-3 font-mono text-xs tracking-[0.1em] text-brand">
                            <span>05 / free_account</span>
                            <span class="rounded-full bg-brand px-2.5 py-[3px] text-[10px] tracking-[0.08em] text-accent-ink">{{ __('no card needed') }}</span>
                        </div>
                        <h2 class="m-0 max-w-[17ch] font-display text-[clamp(32px,5.4vw,76px)] font-extrabold leading-[0.98] tracking-[-0.042em] text-balance">
                            {{ __('Get a price before you commit to anything.') }}
                        </h2>
                        <p class="mt-[22px] max-w-[44ch] text-[17px] leading-relaxed text-muted text-pretty">
                            {{ __('Create a free account, describe the job, and get a written estimate back with a timescale. Have a look round the portal while you\'re there — tickets, progress, files, the same view our clients use.') }}
                        </p>

                        <div class="mt-[clamp(26px,3vw,38px)] flex flex-wrap gap-3.5">
                            <x-rsc.button as="a" href="{{ route('register') }}" class="!px-[30px] !py-4 !text-base">{{ __('Create a free account') }}</x-rsc.button>
                            <x-rsc.button as="a" href="#contact" variant="outline" class="!px-7 !py-4 !text-base">{{ __('Rather just talk?') }}</x-rsc.button>
                        </div>
                        <p class="mt-5 font-mono text-xs text-muted">{{ __('// takes about two minutes, no obligation to go ahead') }}</p>
                    </div>

                    <div class="flex flex-col gap-0.5">
                        @foreach ($steps as $index => [$number, $title, $body, $warm])
                            <div class="grid grid-cols-[auto_1fr] items-baseline gap-[18px] border-t border-line py-5 {{ $loop->last ? 'border-b' : '' }}">
                                <span class="font-mono text-[11px] {{ $warm ? 'text-warm' : 'text-brand' }}">{{ $number }}</span>
                                <span>
                                    <span class="block font-display text-[19px] font-bold tracking-[-0.02em]">{{ $title }}</span>
                                    <span class="mt-[5px] block text-sm text-muted">{{ $body }}</span>
                                </span>
                            </div>
                        @endforeach

                        <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2.5 font-mono text-[11px] text-muted">
                            <span>{{ __('free forever') }}</span>
                            <span>{{ __('your data stays yours') }}</span>
                            <span>{{ __('delete the account any time') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="contact" class="px-[clamp(18px,5vw,64px)] pb-[clamp(40px,5vw,72px)] pt-[clamp(56px,8vw,120px)]">
            <x-rsc.kicker class="mb-4 !tracking-[0.1em] !text-xs">06 / get_in_touch</x-rsc.kicker>
            <h2 class="m-0 mb-[clamp(30px,4vw,56px)] max-w-[14ch] font-display text-[clamp(36px,8vw,124px)] font-extrabold leading-[0.94] tracking-[-0.045em]">
                {{ __('Tell us what you\'re trying to fix.') }}
            </h2>

            <div class="flex flex-wrap gap-[clamp(24px,3vw,52px)]">
                <div class="min-w-[min(100%,300px)] flex-[1_1_340px]">
                    <p class="m-0 mb-[34px] max-w-[38ch] text-base text-muted text-pretty">
                        {{ __('A rough idea is enough. We\'ll come back with what it takes, what it costs, and whether we\'re the right fit.') }}
                    </p>

                    @foreach ([
                        ['mailto:info@rscmedia.co.uk', 'info@rscmedia.co.uk', 'email', false],
                        ['https://wa.me/447522375848', '+44 7522 375848', 'whatsapp', false],
                        ['https://app.squareup.com/appointments/book/sq5vhyoj4c8a3r/LWSJTFAG74PE3/start', 'Book a 30-minute call', 'diary', true],
                    ] as [$href, $label, $kind, $last])
                        <a href="{{ $href }}"
                           class="flex items-center justify-between gap-4 border-t border-line py-5 text-body no-underline transition-all duration-200 hover:ps-3 hover:text-brand {{ $last ? 'border-b' : '' }}">
                            <span class="font-display text-[clamp(17px,2vw,22px)] font-bold tracking-[-0.02em]">{{ $label }}</span>
                            <span class="font-mono text-[11px] text-muted">{{ $kind }}</span>
                        </a>
                    @endforeach
                </div>

                <div class="min-w-[min(100%,300px)] flex-[1_1_400px] rounded-[22px] border border-line bg-panel p-[clamp(24px,3vw,40px)]">
                    @if ($sent)
                        <div class="flex min-h-[340px] flex-col justify-center gap-3.5">
                            <div class="h-0.5 w-[34px] bg-brand"></div>
                            <h3 class="m-0 font-display text-[26px] font-extrabold tracking-[-0.03em]">{{ __('Thanks — that\'s with us.') }}</h3>
                            <p class="m-0 text-sm text-muted">{{ __('We\'ll reply within one working day. WhatsApp is faster if it\'s urgent.') }}</p>
                        </div>
                    @else
                        <form wire:submit="send" class="flex flex-col gap-[18px]">
                            <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                                <x-rsc.field label="name" name="name">
                                    <x-rsc.input wire:model="name" placeholder="Jane Smith" class="!rounded-[10px] !px-[13px] !py-[11px] !text-sm" />
                                </x-rsc.field>
                                <x-rsc.field label="email" name="email">
                                    <x-rsc.input type="email" wire:model="email" placeholder="jane@company.co.uk" class="!rounded-[10px] !px-[13px] !py-[11px] !text-sm" />
                                </x-rsc.field>
                            </div>

                            <x-rsc.field label="company" name="company">
                                <x-rsc.input wire:model="company" placeholder="{{ __('Optional') }}" class="!rounded-[10px] !px-[13px] !py-[11px] !text-sm" />
                            </x-rsc.field>

                            <div>
                                <span class="mb-2.5 block font-mono text-[11px] text-muted">what_is_this_about</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (\App\Models\Enquiry::TOPICS as $value => $label)
                                        <x-rsc.chip wire:click="$set('topic', '{{ $value }}')" :active="$topic === $value" class="!px-[15px] !py-[9px] !text-[13px] !font-sans">
                                            {{ $label }}
                                        </x-rsc.chip>
                                    @endforeach
                                </div>
                            </div>

                            <x-rsc.field label="tell_us_more" name="message">
                                <x-rsc.textarea wire:model="message" rows="4"
                                                placeholder="{{ __('What\'s not working, or what you\'d like to be able to do.') }}"
                                                class="!rounded-[10px] !px-[13px] !py-[11px] !text-sm" />
                            </x-rsc.field>

                            <x-rsc.button type="submit" class="self-start !px-6 !py-3.5 !font-sans !font-semibold">{{ __('Send it over') }}</x-rsc.button>

                            <p class="m-0 font-mono text-[11px] text-muted">{{ __('// we\'ll only use this to reply. no lists, no chasing.') }}</p>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <footer class="border-t border-line px-[clamp(18px,5vw,64px)] py-[clamp(26px,3vw,42px)]">
            <div class="flex flex-wrap items-center justify-between gap-x-11 gap-y-5">
                <x-rsc.logo class="h-[22px] w-auto" />

                <div class="flex flex-wrap gap-x-7 gap-y-2.5 font-mono text-xs">
                    @foreach ($navLinks as [$href, $label])
                        <a href="{{ $href }}" class="text-muted no-underline hover:text-body">{{ $label }}</a>
                    @endforeach
                    <a href="{{ $portal['url'] }}" class="text-muted no-underline hover:text-body">{{ $portal['label'] }}</a>
                </div>

                <div class="font-mono text-xs text-muted">© {{ now()->year }} RSC Media Ltd</div>
            </div>
        </footer>
    </div>
</div>
