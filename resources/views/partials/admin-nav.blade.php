@php
    /**
     * Sub-navigation shared by every administration screen. Kept separate from
     * the top nav so the admin area can grow without crowding the main bar.
     */
    $adminLinks = [
        ['route' => 'admin.users', 'label' => 'Users', 'icon' => 'users'],
        ['route' => 'admin.import', 'label' => 'Import', 'icon' => 'upload'],
        ['route' => 'admin.emergency-log', 'label' => 'Emergency log', 'icon' => 'alert'],
        ['route' => 'admin.misuse', 'label' => 'Misuse', 'icon' => 'chart'],
        ['route' => 'admin.settings', 'label' => 'Settings', 'icon' => 'cog'],
        ['route' => 'admin.broadcast', 'label' => 'Broadcast', 'icon' => 'megaphone'],
    ];
@endphp

<nav class="mb-6 flex flex-wrap items-center gap-1 border-b border-line pb-3" aria-label="Administration">
    @foreach ($adminLinks as $link)
        @php $isActive = request()->routeIs($link['route']); @endphp

        <a href="{{ route($link['route']) }}"
           class="{{ $isActive ? 'nav-item-active' : 'nav-item' }}"
           @if ($isActive) aria-current="page" @endif>
            <x-icon :name="$link['icon']" class="h-4 w-4" />
            <span>{{ $link['label'] }}</span>
        </a>
    @endforeach
</nav>
