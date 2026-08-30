@props(['type' => 'text'])

<input type="{{ $type }}" {{ $attributes->class(
    'w-full rounded-xl border border-line bg-transparent px-4 py-3.5 text-[15px] transition-colors duration-200 focus:border-brand'
) }}>
