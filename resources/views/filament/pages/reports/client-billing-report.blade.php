<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="flex gap-2">
            <x-filament::button
                :color="$viewMode === 'summary' ? 'primary' : 'gray'"
                wire:click="$set('viewMode', 'summary')"
                size="sm"
            >
                Summary
            </x-filament::button>
            <x-filament::button
                :color="$viewMode === 'detail' ? 'primary' : 'gray'"
                wire:click="$set('viewMode', 'detail')"
                size="sm"
            >
                Billable Event Log
            </x-filament::button>
        </div>

        @if ($viewMode === 'detail')
            <select
                wire:model.live="clientId"
                class="fi-select-input rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white text-sm"
            >
                @foreach ($this->getClientOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{ $this->table }}
</x-filament-panels::page>
