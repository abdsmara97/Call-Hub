@props(['user', 'size' => 'md', 'presence' => false])

@php
    $dimensions = [
        'xs' => 'h-6 w-6 text-2xs',
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-9 w-9 text-xs',
        'lg' => 'h-12 w-12 text-sm',
        'xl' => 'h-20 w-20 text-lg',
    ][$size] ?? 'h-9 w-9 text-xs';

    $dotSize = in_array($size, ['xs', 'sm'], true) ? 'h-2 w-2' : 'h-2.5 w-2.5';
@endphp

<span {{ $attributes->merge(['class' => 'relative inline-flex shrink-0']) }}>
    @if ($user->avatar_url)
        <img src="{{ $user->avatar_url }}" alt=""
             class="{{ $dimensions }} rounded-full object-cover ring-1 ring-line" />
    @else
        <span class="{{ $dimensions }} inline-flex items-center justify-center rounded-full
                     bg-brand-tint-strong font-semibold text-brand-text ring-1 ring-brand-border"
              aria-hidden="true">{{ $user->initials }}</span>
    @endif

    @if ($presence)
        {{-- Presence is conveyed by colour *and* by the title text, never colour alone. --}}
        <span class="absolute -bottom-0.5 -right-0.5 {{ $dotSize }} rounded-full ring-2 ring-surface
                     {{ $user->availability->dotClass() }}"
              title="{{ $user->availability->label() }}"></span>
        <span class="sr-only">{{ $user->availability->label() }}</span>
    @endif
</span>
