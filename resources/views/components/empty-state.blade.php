@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700']) }}>
    <flux:heading>{{ $title }}</flux:heading>
    @if ($description)
        <flux:subheading class="mt-1">{{ $description }}</flux:subheading>
    @endif
    @if (isset($slot) && trim($slot) !== '')
        <div class="mt-4 flex justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
