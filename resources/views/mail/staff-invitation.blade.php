<x-mail::message>
# You're invited to {{ $workspace }}

{{ $inviter }} has invited you to join **{{ $workspace }}** on {{ config('app.name') }}.

Click the button below to choose a password and activate your account.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires {{ $expires->diffForHumans() }}. If you weren't
expecting it, you can safely ignore this email — nothing happens without
the link.

{{ config('app.name') }}
</x-mail::message>
