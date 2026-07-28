<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-emergency']) }}>
    {{ $slot }}
</button>
