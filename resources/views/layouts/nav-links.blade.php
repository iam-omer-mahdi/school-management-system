@php
$links = [
    ['route' => 'dashboard', 'match' => 'dashboard', 'label' => __('common.dashboard'), 'icon' => 'home'],
    ['route' => 'students.index', 'match' => 'students.*', 'label' => __('common.students'), 'icon' => 'users'],
    ['route' => 'academic-years.index', 'match' => 'academic-years.*', 'label' => __('common.academic_years'), 'icon' => 'calendar'],
    ['route' => 'schools.index', 'match' => 'schools.*', 'label' => __('common.schools'), 'icon' => 'cap'],
    ['route' => 'settings', 'match' => 'settings', 'label' => __('common.settings'), 'icon' => 'cog'],
];
$drawerId = $drawerId ?? null;
@endphp

<nav class="flex flex-col gap-1" aria-label="{{ __('common.main_nav') }}">
    @foreach($links as $link)
        @php($isActive = request()->routeIs($link['match']))
        <a
            href="{{ route($link['route']) }}"
            wire:navigate.hover
            @if($drawerId) data-drawer-hide="{{ $drawerId }}" @endif
            @if($isActive) aria-current="page" @endif
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition focus:outline-none focus:ring-4 focus:ring-indigo-500 {{ $isActive ? 'bg-indigo-600 text-white shadow-xs' : 'text-gray-300 hover:bg-gray-700 hover:text-white' }}"
        >
            <x-ui.icon :name="$link['icon']" class="h-5 w-5 shrink-0" />
            <span class="truncate">{{ $link['label'] }}</span>
        </a>
    @endforeach
</nav>
