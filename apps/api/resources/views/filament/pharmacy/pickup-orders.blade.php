<x-filament-panels::page>
    <p>Confirm only the medicines and quantities the customer requested, unless this is a full prescription image with an accepted quote. Cash is recorded at pickup. Nothing is charged online.</p>

    <label>
        Reason
        <input type="text" wire:model="reason">
    </label>
    <label>
        Pickup start (Asia/Karachi)
        <input type="text" wire:model="startsAt" placeholder="2026-10-02 10:00">
    </label>
    <label>
        Pickup end (Asia/Karachi)
        <input type="text" wire:model="endsAt" placeholder="2026-10-02 18:00">
    </label>
    <label>
        Cash collected, in paisa
        <input type="text" wire:model="cashPaisa">
    </label>

    @php($selected = $this->quoteOrder())
    @if ($selected)
        @php($quote = $this->currentQuote())
        @php($prescription = $this->quotePrescription())
        <section>
            <h2>Quote for {{ $selected->reference }}</h2>
            <p>{{ $selected->status->value }} · {{ $selected->request_mode->value }} · estimate {{ $selected->estimate_total_paisa === null ? 'none' : $selected->estimate_total_paisa }}</p>
            @forelse ($selected->items as $item)
                <p>{{ $item->generic_name }} · {{ $item->medicine_id }} · quantity {{ $item->quantity }}</p>
            @empty
                <p>No submitted catalog lines.</p>
            @endforelse
            @if ($quote)
                <p>Quote version {{ $quote->version }} · {{ $quote->state }} · total {{ $quote->total_paisa }}</p>
            @else
                <p>Quote version none</p>
            @endif
            @if ($prescription)
                <p>Prescription {{ $prescription['share_mode'] }} · version {{ $prescription['content_version'] }} · {{ $prescription['share_state'] }}</p>
            @endif
            @if ($selected->request_mode->value === 'prescription_full_image' && $selected->status->value === 'pending_pharmacy_review' && $selected->prescription_usable)
                <button type="button" wire:click="viewPrescription('{{ $selected->public_id }}')">View original file</button>
            @endif
            <p>A quote can contain at most {{ $this->lineLimit() }} medicines.</p>
            <label>
                Reviewed medicine
                <input type="text" wire:model="medicineId">
            </label>
            <label>
                Quantity
                <input type="text" wire:model="quantity">
            </label>
            <label>
                Unit price, in paisa
                <input type="text" wire:model="unitPrice">
            </label>
            <button type="button" wire:click="addLine">Add reviewed line</button>
            @foreach ($draftLines as $line)
                <p>Reviewed {{ $line['medicine_id'] }} · quantity {{ $line['quantity'] }} · unit {{ $line['unit_price_paisa'] }}</p>
            @endforeach
            <button type="button" wire:click="discardDraft">Discard draft</button>
            <button type="button" wire:click="proposeQuote('{{ $selected->public_id }}')">Send quote</button>
        </section>
    @endif

    @php($orders = $this->orders())
    @foreach ($orders as $order)
        <article wire:key="order-{{ $order->public_id }}">
            <h2>{{ $order->reference }}</h2>
            <p>{{ $order->status->value }} · {{ $order->request_mode->value }} · estimate {{ $order->estimate_total_paisa === null ? 'none' : $order->estimate_total_paisa }}</p>
            <button type="button" wire:click="selectOrder('{{ $order->public_id }}')">Review quote</button>
            <button type="button" wire:click="confirmOrder('{{ $order->public_id }}')">Confirm</button>
            <button type="button" wire:click="rejectOrder('{{ $order->public_id }}')">Reject</button>
            <button type="button" wire:click="markReady('{{ $order->public_id }}')">Mark ready</button>
            <button type="button" wire:click="collectOrder('{{ $order->public_id }}')">Record collection</button>
            <button type="button" wire:click="closeNoShow('{{ $order->public_id }}')">Record no-show</button>
        </article>
    @endforeach

    <p>Page {{ $this->queuePage }} of {{ $this->queueLastPage() }}</p>
    <button type="button" wire:click="previousQueuePage">Previous orders</button>
    <button type="button" wire:click="nextQueuePage">Next orders</button>
</x-filament-panels::page>
