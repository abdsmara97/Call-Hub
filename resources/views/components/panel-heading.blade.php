@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'mb-5']) }}>
    <h1 class="text-xl font-semibold tracking-tight">{{ $title }}</h1>

    @if ($description)
        <p class="mt-1 text-sm text-content-muted">{{ $description }}</p>
    @endif

    {{ $slot ?? '' }}
</div>
