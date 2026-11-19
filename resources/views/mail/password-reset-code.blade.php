<x-mail::message>
# Reset your password

Hi {{ $name }},

Someone asked to reset the password for your {{ config('app.name') }} account. Enter this code on the reset page to choose a new one. It expires in {{ $minutes }} minutes.

<x-mail::panel>
<span style="font-size: 28px; letter-spacing: 6px; font-family: monospace;">{{ $code }}</span>
</x-mail::panel>

<x-mail::button :url="$url">
Open the reset page
</x-mail::button>

Resetting signs out every browser and revokes your access tokens. If you did not ask for this, ignore this email: your password stays the same.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
