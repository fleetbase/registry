<x-mail::message>
# Verify your email

Hi {{ $name }},

Enter this code to verify your {{ config('app.name') }} account. It expires in {{ $minutes }} minutes.

<x-mail::panel>
<span style="font-size: 28px; letter-spacing: 6px; font-family: monospace;">{{ $code }}</span>
</x-mail::panel>

From the CLI, run `flb verify --email {{ $account->email }} --code {{ $code }}`.

If you did not create an account you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
