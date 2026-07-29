@props(['message', 'class' => ''])

{{--
    A message body, with any mentions highlighted.

    The app renders user content as plain text and emits raw HTML nowhere — there
    is no {!! !!} in this codebase and this component does not introduce one. The
    body is split into segments in PHP and each is escaped by Blade's own {{ }};
    the <span> below is literal template text, never built from user data. That
    is why a colleague whose name contains markup still renders as escaped text
    *inside* the highlight, which is the case that breaks the tempting
    escape-then-string-replace approach.

    The loop is deliberately on one line: the container is whitespace-pre-wrap,
    so any newline Blade left between these directives would show up as a stray
    space in the message.
--}}
<div {{ $attributes->merge(['class' => 'whitespace-pre-wrap break-words '.$class]) }}>@foreach ($message->bodySegments() as $segment)@if ($segment['user_id'])<span class="rounded-sm px-0.5 font-medium {{ $segment['user_id'] === auth()->id() ? 'bg-brand-tint-strong text-brand-text' : 'bg-brand-tint text-brand-text' }}">{{ $segment['text'] }}</span>@if ($segment['user_id'] === auth()->id())<span class="sr-only"> (mentions you)</span>@endif@else{{ $segment['text'] }}@endif@endforeach</div>
