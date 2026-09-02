{{-- The fields for opening a client account. Shared by the invoices panel and
     the settings screen so the two cannot drift apart. --}}
<div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
    <x-rsc.field label="business" name="newBusiness" class="[grid-column:1/-1]">
        <x-rsc.input wire:model="newBusiness" placeholder="Braemar Joinery" class="!py-3" />
    </x-rsc.field>

    <x-rsc.field label="contact_name" name="newContactName">
        <x-rsc.input wire:model="newContactName" placeholder="Kirsty Munro" class="!py-3" />
    </x-rsc.field>

    <x-rsc.field label="job_title" name="newJobTitle">
        <x-rsc.input wire:model="newJobTitle" placeholder="{{ __('Office Manager — optional') }}" class="!py-3" />
    </x-rsc.field>

    <x-rsc.field label="email" name="newContactEmail" class="[grid-column:1/-1]"
                 hint="{{ __('They get an email to set their own password. The link lasts a week.') }}">
        <x-rsc.input type="email" wire:model="newContactEmail" placeholder="kirsty@braemarjoinery.co.uk" class="!py-3" />
    </x-rsc.field>
</div>
