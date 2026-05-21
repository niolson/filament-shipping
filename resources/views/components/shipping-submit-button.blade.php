@props([
    'enabledVariable' => 'autoShipEnabled',
    'loadingType' => 'alpine',
    'loadingVariable' => 'isShipping',
    'loadingTarget' => null,
])

@php
    $colorClasses = (new \Illuminate\View\ComponentAttributeBag)
        ->color(app(\Filament\Support\View\Components\ButtonComponent::class), 'primary')
        ->get('class');
@endphp

<button
    {{ $attributes->merge([
        'class' => 'fi-btn fi-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm disabled:opacity-50 disabled:pointer-events-none ' . $colorClasses,
    ]) }}
>
    @if ($loadingType === 'wire')
        @if ($loadingTarget)
            <x-filament::icon wire:loading.remove wire:target="{{ $loadingTarget }}" icon="heroicon-o-paper-airplane" class="fi-btn-icon h-5 w-5" />
            <x-filament::loading-indicator wire:loading wire:target="{{ $loadingTarget }}" class="h-5 w-5" />
            <span wire:loading.remove wire:target="{{ $loadingTarget }}" x-text="{{ $enabledVariable }} ? 'Auto Ship' : 'Ship'"></span>
            <span wire:loading wire:target="{{ $loadingTarget }}">Working...</span>
        @else
            <x-filament::icon wire:loading.remove icon="heroicon-o-paper-airplane" class="fi-btn-icon h-5 w-5" />
            <x-filament::loading-indicator wire:loading class="h-5 w-5" />
            <span wire:loading.remove x-text="{{ $enabledVariable }} ? 'Auto Ship' : 'Ship'"></span>
            <span wire:loading>Working...</span>
        @endif
    @else
        <template x-if="{{ $loadingVariable }}">
            <x-filament::loading-indicator class="h-5 w-5" />
        </template>
        <template x-if="!{{ $loadingVariable }}">
            <x-filament::icon
                icon="heroicon-o-paper-airplane"
                class="fi-btn-icon h-5 w-5"
            />
        </template>
        <span x-text="{{ $enabledVariable }} ? 'Auto Ship' : 'Ship'"></span>
    @endif
</button>
