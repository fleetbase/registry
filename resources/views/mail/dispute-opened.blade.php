<x-mail::message>
# A purchase of {{ $extension }} is disputed

{{ $buyer ?? 'A buyer' }} disputed a payment of {{ $amount }} to {{ $publisher ?? 'the publisher' }}{{ $reason ? ' (reason: '.$reason.')' : '' }}. The purchase is flagged and its licence is revoked. If the dispute is won, reinstate the licence{{ $licenceId ? ' (`'.$licenceId.'`)' : '' }} with `POST /v1/admin/licences/{id}/reinstate`.

Respond in the Stripe Dashboard{{ $disputeId ? ' (dispute `'.$disputeId.'`)' : '' }}.

Purchase id: `{{ $purchaseId }}`
</x-mail::message>
