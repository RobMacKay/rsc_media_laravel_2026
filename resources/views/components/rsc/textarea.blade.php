<textarea {{ $attributes->class(
    'w-full resize-y rounded-xl border border-line bg-transparent px-4 py-3.5 text-[15px] transition-colors duration-200 focus:border-brand'
) }}>{{ $slot ?? '' }}</textarea>
