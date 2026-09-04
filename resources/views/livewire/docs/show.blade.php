<div class="flex h-full w-full flex-1 flex-col">
    <x-page-header title="Documentation" subtitle="How to use BrookeApp, step by step" class="print:hidden">
        <x-slot:actions>
            @if ($hasPdf)
                <flux:button :href="route('docs.pdf')" target="_blank" icon="arrow-down-tray" variant="primary">Download PDF</flux:button>
            @endif
            <flux:button icon="printer" onclick="window.print()">Print this page</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex items-start max-md:flex-col">
        <nav class="mr-10 w-full pb-4 md:w-[240px] print:hidden" aria-label="Chapters">
            <flux:navlist>
                @foreach ($chapters as $index => $item)
                    <flux:navlist.item :href="route('docs.show', $item['slug'])" :current="$item['slug'] === $chapter['slug']" wire:navigate>
                        <span class="mr-1.5 tabular-nums text-zinc-400">{{ $index + 1 }}.</span>{{ $item['title'] }}
                    </flux:navlist.item>
                @endforeach
            </flux:navlist>
        </nav>

        <flux:separator class="md:hidden print:hidden" />

        <div class="min-w-0 flex-1 self-stretch max-md:pt-6">
            <flux:heading size="xl" level="2">{{ $chapter['title'] }}</flux:heading>
            @if ($chapter['summary'])
                <flux:subheading size="lg">{{ $chapter['summary'] }}</flux:subheading>
            @endif

            @if (count($headings) > 1)
                <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
                    <div class="mb-1 font-medium text-zinc-700 dark:text-zinc-200">In this chapter</div>
                    <ol class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        @foreach ($headings as $heading)
                            <li><flux:link href="#{{ $heading['id'] }}" variant="subtle">{{ $heading['text'] }}</flux:link></li>
                        @endforeach
                    </ol>
                </div>
            @endif

            <article class="docs-prose mt-6 max-w-3xl">
                {!! $html !!}
            </article>

            <div class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700 print:hidden">
                <div>
                    @if ($previous)
                        <flux:button :href="route('docs.show', $previous['slug'])" icon="chevron-left" variant="ghost" wire:navigate>{{ $previous['title'] }}</flux:button>
                    @endif
                </div>
                <div>
                    @if ($next)
                        <flux:button :href="route('docs.show', $next['slug'])" icon-trailing="chevron-right" variant="ghost" wire:navigate>{{ $next['title'] }}</flux:button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
