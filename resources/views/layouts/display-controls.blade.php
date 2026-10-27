@php($otherLocale = app()->getLocale() === 'ar' ? 'en' : 'ar')
<div class="flex shrink-0 items-center gap-2" role="group" aria-label="{{ __('common.language') }} & {{ __('common.theme_toggle') }}">
    <button type="button" data-theme-toggle class="inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-xs border border-line-strong bg-surface text-muted transition hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" aria-label="{{ __('common.theme_toggle') }}">
        <svg class="h-5 w-5 shrink-0 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0012 21.75a9.753 9.753 0 009.752-6.748z" /></svg>
        <svg class="hidden h-5 w-5 shrink-0 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
    </button>
    <form method="POST" action="{{ route('locale.switch') }}" class="flex shrink-0 items-center">
        @csrf
        <input type="hidden" name="locale" value="{{ $otherLocale }}">
        <button type="submit" class="inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-xs border border-line-strong bg-surface px-3 text-sm font-medium whitespace-nowrap text-ink transition hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            <x-ui.icon name="globe" class="h-4 w-4 shrink-0 text-muted" />
            {{ $otherLocale === 'ar' ? __('common.arabic') : __('common.english') }}
        </button>
    </form>
</div>
