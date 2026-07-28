@props(['status' => null])

@if ($status)
    <div {{ $attributes->merge(['class' => 'mb-4 rounded-md bg-brand-tint px-3 py-2 text-sm font-medium text-brand-text']) }}>
        {{ $status }}
    </div>
@endif
