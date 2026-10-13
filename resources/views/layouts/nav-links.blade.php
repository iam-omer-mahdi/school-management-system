@php
$links = [
    ['route' => 'dashboard', 'match' => 'dashboard', 'label' => __('common.dashboard'), 'icon' => 'home'],
    ['route' => 'students.index', 'match' => 'students.*', 'label' => __('common.students'), 'icon' => 'users'],
    ['route' => 'enrollments.index', 'match' => 'enrollments.*', 'label' => __('enrollments.title'), 'icon' => 'clipboard'],
    ['route' => 'academic-years.index', 'match' => 'academic-years.*', 'label' => __('common.academic_years'), 'icon' => 'calendar'],
    ['route' => 'schools.index', 'match' => 'schools.*', 'label' => __('common.schools'), 'icon' => 'cap'],
    ['route' => 'settings', 'match' => 'settings', 'label' => __('common.settings'), 'icon' => 'cog'],
];
$feeLinks = [
    ['route' => 'fee-types.index', 'match' => 'fee-types.*', 'label' => __('fees.fee_types'), 'icon' => 'tag'],
    ['route' => 'fee-schedule.index', 'match' => 'fee-schedule.*', 'label' => __('fees.schedule'), 'icon' => 'calendar'],
    ['route' => 'discounts.index', 'match' => 'discounts.*', 'label' => __('fees.discounts'), 'icon' => 'currency'],
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

    <p class="mt-3 px-3 text-xs font-semibold tracking-wider text-gray-400 uppercase dark:text-gray-500">{{ __('common.fees') }}</p>
    @foreach($feeLinks as $link)
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
