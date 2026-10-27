@php
$links = [
    ['route' => 'dashboard', 'match' => 'dashboard', 'label' => __('common.dashboard'), 'icon' => 'home'],
    ['route' => 'students.index', 'match' => 'students.*', 'label' => __('common.students'), 'icon' => 'users', 'permission' => \App\Enums\Permission::StudentsView->value],
    ['route' => 'enrollments.index', 'match' => 'enrollments.*', 'label' => __('enrollments.title'), 'icon' => 'clipboard', 'permission' => \App\Enums\Permission::EnrollmentsView->value],
];
$feeLinks = [
    ['route' => 'fee-types.index', 'match' => 'fee-types.*', 'label' => __('fees.fee_types'), 'icon' => 'tag', 'permission' => \App\Enums\Permission::FeesView->value],
    ['route' => 'fee-schedule.index', 'match' => 'fee-schedule.*', 'label' => __('fees.schedule'), 'icon' => 'calendar', 'permission' => \App\Enums\Permission::FeesView->value],
    ['route' => 'discounts.index', 'match' => 'discounts.*', 'label' => __('fees.discounts'), 'icon' => 'currency', 'permission' => \App\Enums\Permission::FeesView->value],
];
$tailLinks = [
    ['route' => 'reports.index', 'match' => 'reports.*', 'label' => __('reports.reports'), 'icon' => 'clipboard', 'permission' => \App\Enums\Permission::ReportsView->value],
    ['route' => 'schools.index', 'match' => 'schools.*', 'label' => __('common.schools'), 'icon' => 'cap'],
    ['route' => 'academic-years.index', 'match' => 'academic-years.*', 'label' => __('common.academic_years'), 'icon' => 'calendar'],
    ['route' => 'staff.index', 'match' => 'staff.*', 'label' => __('staff.title'), 'icon' => 'users', 'permission' => \App\Enums\Permission::StaffManage->value],
    ['route' => 'help', 'match' => 'help', 'label' => __('common.help'), 'icon' => 'help'],
    ['route' => 'settings', 'match' => 'settings', 'label' => __('common.settings'), 'icon' => 'cog'],
];
$drawerId = $drawerId ?? null;
$visible = fn (array $links): array => array_values(array_filter(
    $links,
    fn (array $link): bool => ! isset($link['permission']) || (bool) auth()->user()?->can($link['permission'])
));
$feeLinks = $visible($feeLinks);
$links = $visible($links);
$tailLinks = $visible($tailLinks);
@endphp

<nav class="flex flex-col gap-0.5" aria-label="{{ __('common.main_nav') }}">
    @foreach($links as $link)
        @php($isActive = request()->routeIs($link['match']))
        <a
            href="{{ route($link['route']) }}"
            wire:navigate.hover
            @if($drawerId) data-drawer-hide="{{ $drawerId }}" @endif
            @if($isActive) aria-current="page" @endif
            class="relative flex min-h-11 items-center gap-3 rounded-xs px-3 py-2 text-sm font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus {{ $isActive ? 'bg-nav-active text-nav-active-fg before:absolute before:inset-y-2 before:start-0 before:w-0.5 before:rounded-full before:bg-nav-marker' : 'text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-fg' }}"
        >
            <x-ui.icon :name="$link['icon']" class="h-5 w-5 shrink-0" />
            <span class="truncate">{{ $link['label'] }}</span>
        </a>
    @endforeach

    @if($feeLinks)
        <p class="mt-6 px-3 pb-1 text-[11px] font-semibold tracking-wider text-sidebar-muted uppercase">{{ __('common.fees') }}</p>
    @endif
    @foreach($feeLinks as $link)
        @php($isActive = request()->routeIs($link['match']))
        <a
            href="{{ route($link['route']) }}"
            wire:navigate.hover
            @if($drawerId) data-drawer-hide="{{ $drawerId }}" @endif
            @if($isActive) aria-current="page" @endif
            class="relative flex min-h-11 items-center gap-3 rounded-xs px-3 py-2 text-sm font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus {{ $isActive ? 'bg-nav-active text-nav-active-fg before:absolute before:inset-y-2 before:start-0 before:w-0.5 before:rounded-full before:bg-nav-marker' : 'text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-fg' }}"
        >
            <x-ui.icon :name="$link['icon']" class="h-5 w-5 shrink-0" />
            <span class="truncate">{{ $link['label'] }}</span>
        </a>
    @endforeach
    @foreach($tailLinks as $link)
        @php($isActive = request()->routeIs($link['match']))
        <a
            href="{{ route($link['route']) }}"
            wire:navigate.hover
            @if($drawerId) data-drawer-hide="{{ $drawerId }}" @endif
            @if($isActive) aria-current="page" @endif
            class="relative flex min-h-11 items-center gap-3 rounded-xs px-3 py-2 text-sm font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus {{ $isActive ? 'bg-nav-active text-nav-active-fg before:absolute before:inset-y-2 before:start-0 before:w-0.5 before:rounded-full before:bg-nav-marker' : 'text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-fg' }}"
        >
            <x-ui.icon :name="$link['icon']" class="h-5 w-5 shrink-0" />
            <span class="truncate">{{ $link['label'] }}</span>
        </a>
    @endforeach
</nav>
