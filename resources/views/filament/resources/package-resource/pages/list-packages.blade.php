<x-filament-panels::page>
    <x-qz-tray />

    @include('filament.components.list-record-tab-groups', ['groups' => $this->getTabGroups()])

    {{ $this->table }}
</x-filament-panels::page>
