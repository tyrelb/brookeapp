@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900']) }}>
    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $value }}</div>
    @if ($hint)
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
    @endif
</div>
