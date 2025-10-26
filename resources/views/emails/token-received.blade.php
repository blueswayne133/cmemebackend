<x-mail::message>
# You've Received {{ $currency }} Tokens!

Hello!

You've just received {{ $currency }} tokens from **{{ $senderUsername }}**.

Login to your account to check your updated balance.

<x-mail::button :url="url('/dashboard')">
View Your Account
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>