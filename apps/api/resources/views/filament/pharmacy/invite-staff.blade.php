<x-filament-panels::page>
    <form wire:submit="invite">
        {{ $this->form }}
        <button type="submit">Send invitation</button>
    </form>
</x-filament-panels::page>
