<x-mail::message>
# Thanks for buying {{ $extension }}

{{ $organization ? $organization.' now holds' : 'Your organization now holds' }} a licence for {{ $extension }}{{ $seats ? ' on up to '.$seats.' instances' : '' }}. Install it from your console's Extensions page.

**Paid:** {{ $amount }}

@if ($receiptUrl)
<x-mail::button :url="$receiptUrl">
View the receipt
</x-mail::button>
@endif

Your organization's purchases and licences are on the [billing page]({{ $billingUrl }}).

Purchase id: `{{ $purchaseId }}`

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
