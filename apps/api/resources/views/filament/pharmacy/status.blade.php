<x-filament-panels::page>
    @php($pharmacy = $this->pharmacy())
    @if ($pharmacy)
        <p>Operational status: {{ $pharmacy->status->value }}</p>
        <p>License status: {{ $pharmacy->license_status->value }}</p>
        <p>Location review: {{ $pharmacy->location_review_status->value }}</p>
        <p>Public eligibility: {{ $pharmacy->publiclyEligible() ? 'eligible' : 'not eligible' }}</p>
        @if ($pharmacy->status_reason)
            <p>Decision note: {{ $pharmacy->status_reason }}</p>
        @endif
        <p>A typed license number is not verification. Verification is recorded only after an administrator review.</p>
    @endif
</x-filament-panels::page>
