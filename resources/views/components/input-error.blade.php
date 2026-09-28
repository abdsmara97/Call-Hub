@props(['messages' => null])

{{--
    Flattened, because a wildcard key does not return a flat list.
    `$errors->get('uploads.*')` hands back an array keyed by the attribute that
    failed — ['uploads.0' => ['The attachment is too large']] — so echoing each
    entry directly passes an array to htmlspecialchars and throws. The visible
    symptom was a 500 in place of "your file is too large", on exactly the paths
    where a validation message matters most.

    Applies equally to `pollOptions.*`. Flattening here rather than at each call
    site means the next wildcard key added cannot reintroduce it.
--}}
@php
    $lines = array_filter(
        \Illuminate\Support\Arr::flatten((array) $messages),
        fn ($line) => filled($line),
    );
@endphp

@if ($lines)
    <ul {{ $attributes->merge(['class' => 'field-error space-y-1']) }}>
        @foreach ($lines as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
