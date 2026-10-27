<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'topic')]
    public string $category = 'all';

    /**
     * @return array<string, array{title: string, intro: string, steps: array<int, string>, notes: array<int, string>}>
     */
    #[Computed]
    public function categories(): array
    {
        return __('help.sections');
    }

    /**
     * @return array<string, array{title: string, intro: string, steps: array<int, string>, notes: array<int, string>}>
     */
    #[Computed]
    public function sections(): array
    {
        $sections = $this->categories;

        if ($this->category !== 'all' && array_key_exists($this->category, $sections)) {
            $sections = [$this->category => $sections[$this->category]];
        }

        $term = trim($this->search);

        if ($term === '') {
            return $sections;
        }

        return array_filter(
            $sections,
            fn (array $section): bool => mb_stripos(
                implode("\n", [$section['title'], $section['intro'], ...$section['steps'], ...$section['notes']]),
                $term,
            ) !== false,
        );
    }

    public function setCategory(string $category): void
    {
        $this->category = $category === 'all' || array_key_exists($category, $this->categories)
            ? $category
            : 'all';
    }
};
?>

@php
$icons = [
    'dashboard' => 'home',
    'schools' => 'cap',
    'academic_years' => 'calendar',
    'students' => 'users',
    'enrollment' => 'clipboard',
    'fees' => 'tag',
    'payments' => 'currency',
    'refunds' => 'currency',
    'reports' => 'clipboard',
    'staff_roles' => 'users',
    'account' => 'cog',
];
@endphp

<div class="space-y-8 lg:space-y-10">
    <x-ui.page-header :title="__('help.title')" :description="__('help.intro')" />

    <div role="search" class="relative max-w-md">
        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-subtle">
            <x-ui.icon name="search" class="h-4 w-4" />
        </span>
        <x-ui.input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('help.search_placeholder')" aria-label="{{ __('help.search_placeholder') }}" class="ps-9" />
    </div>

    <nav class="-mx-1 flex gap-1.5 overflow-x-auto p-1" aria-label="{{ __('help.toc_label') }}">
        <button type="button" wire:click="setCategory('all')" @if($category === 'all') aria-current="true" @endif class="help-category-btn">
            <x-ui.icon name="help" class="h-4 w-4" />
            {{ __('help.all_categories') }}
        </button>

        @foreach($this->categories as $key => $section)
            <button type="button" wire:click="setCategory('{{ $key }}')" @if($category === $key) aria-current="true" @endif class="help-category-btn">
                <x-ui.icon :name="$icons[$key] ?? 'help'" class="h-4 w-4" />
                {{ $section['title'] }}
            </button>
        @endforeach
    </nav>

    <p role="status" class="sr-only">{{ __('help.results_count', ['count' => count($this->sections)]) }}</p>

    <div class="space-y-6">
        @forelse($this->sections as $key => $section)
            <x-ui.card wire:key="help-{{ $key }}" class="help-section-card">
                <header class="help-section-header">
                    <span class="help-section-icon">
                        <x-ui.icon :name="$icons[$key] ?? 'help'" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="help-section-title">{{ $section['title'] }}</h2>
                        @if(filled($section['intro']))
                            <p class="help-section-intro">{{ $section['intro'] }}</p>
                        @endif
                    </div>
                </header>

                @if(filled($section['steps']))
                    <ol class="help-steps" role="list">
                        @foreach($section['steps'] as $index => $step)
                            <li class="help-step">
                                <span class="help-step-number">{{ $index + 1 }}</span>
                                <p class="help-step-text">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if(filled($section['notes']))
                    <ul class="help-notes" role="list">
                        @foreach($section['notes'] as $note)
                            <li class="help-note">
                                <x-ui.icon name="check-circle" class="help-note-icon" />
                                <span>{{ $note }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @empty
            <x-ui.empty-state :title="__('help.no_results_title')" :description="__('help.no_results_description', ['term' => trim($search)])" />
        @endforelse
    </div>
</div>
