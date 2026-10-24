<x-mail::message>
@if ($decision === 'published')
# {{ $extension }} {{ $version }} is published

{{ $name ? 'Hi '.$name.',' : 'Hi,' }}

{{ $extension }} {{ $version }} is live in the Fleetbase catalog and on the package endpoints.
@elseif ($decision === 'rejected')
# {{ $extension }} {{ $version }} was not approved

{{ $name ? 'Hi '.$name.',' : 'Hi,' }}

A reviewer did not approve this release.

**Reason:** {{ $reason }}

@foreach ($items as $item)
- {{ ($item['passed'] ?? false) ? '✓' : '✗' }} {{ $item['code'] ?? '' }}{{ !empty($item['note']) ? ': '.$item['note'] : '' }}
@endforeach

Fix the points above and submit again with `flb release submit`, or upload a new version.
@else
# {{ $extension }} {{ $version }} failed its checks

{{ $name ? 'Hi '.$name.',' : 'Hi,' }}

@foreach ($failedChecks as $line)
- {{ $line }}
@endforeach

Run `flb release status` for the full output, fix the problems and upload the release again.
@endif

Release id: `{{ $releaseId }}`

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
