<x-mail::message>
# A purchase of {{ $extension }} is disputed

{{ $buyer ?? 'A buyer' }} disputed a payment of {{ $amount }} to {{ $publisher ?? 'the publisher' }}{{ $reason ? ' (reason: '.$reason.')' : '' }}. The purchase is flagged and its licence stays active while the dispute is open.

Respond in the Stripe Dashboard{{ $disputeId ? ' (dispute `'.$disputeId.'`)' : '' }}.

Purchase id: `{{ $purchaseId }}`
</x-mail::message>
