@props(['title', 'subtitle' => null])

<div class="space-y-6">
    <x-page-header :title="$title" :subtitle="$subtitle">
        <x-slot:actions>
            <flux:navbar>
                <flux:navbar.item :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>Overview</flux:navbar.item>
                <flux:navbar.item :href="route('admin.trainers.index')" :current="request()->routeIs('admin.trainers.*')" wire:navigate>Trainers</flux:navbar.item>
            </flux:navbar>
        </x-slot:actions>
    </x-page-header>

    {{ $slot }}
</div>
