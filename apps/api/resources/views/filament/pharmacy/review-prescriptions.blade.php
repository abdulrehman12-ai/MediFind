<x-filament-panels::page>
    <p>Extraction text and a customer selection are not pharmacist verification. This screen does not reserve stock or create an order.</p>

    @foreach ($this->pending() as $prescription)
        <article wire:key="rx-{{ $prescription->public_id }}">
            <h2>Shared prescription {{ $prescription->public_id }}</h2>
            <p>Mode: {{ $prescription->share_mode }}. Version {{ $prescription->content_version }}. Extraction: {{ $prescription->extraction_state }}.</p>
            <p>A full image is not a claim that every medicine in the file was identified.</p>
            <button type="button" wire:click="download('{{ $prescription->public_id }}')">View original file</button>
            <ul>
                @foreach ($prescription->items->where('extraction_version', $prescription->extraction_version) as $item)
                    <li>
                        Line {{ $item->line_index }}:
                        {{ $item->corrected_text ?? $item->extracted_text }}
                        ({{ $item->match_status }}, confidence unavailable)
                        @if ($item->customer_selected)
                            Customer selected {{ $item->selected_medicine_id }}.
                        @else
                            Not selected by the customer.
                        @endif
                    </li>
                @endforeach
            </ul>
            <label>
                Rejection reason
                <input type="text" wire:model="reason">
            </label>
            <button type="button" wire:click="verifyPrescription('{{ $prescription->public_id }}')">Record verified</button>
            <button type="button" wire:click="rejectPrescription('{{ $prescription->public_id }}')">Record rejected</button>
        </article>
    @endforeach
</x-filament-panels::page>
