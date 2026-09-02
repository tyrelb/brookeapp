@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between']) }}>
    <div>
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:subheading size="lg">{{ $subtitle }}</flux:subheading>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
