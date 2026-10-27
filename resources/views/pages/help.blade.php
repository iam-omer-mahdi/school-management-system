<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component {};
?>

<div class="space-y-8 lg:space-y-10">
    <x-ui.page-header :title="__('help.title')" />

    <x-ui.card>
        <p class="text-sm text-muted ">{{ __('help.intro') }}</p>
    </x-ui.card>

    @foreach(__('help.sections') as $section)
        <x-ui.card :title="$section['title']">
            <div class="space-y-4">
                @if(! empty($section['intro']))
                    <p class="text-sm text-muted ">{{ $section['intro'] }}</p>
                @endif

                @if(! empty($section['steps']))
                    <ol class="list-decimal space-y-1.5 ps-5 text-sm text-ink ">
                        @foreach($section['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                @endif

                @if(! empty($section['notes']))
                    <ul class="space-y-1.5">
                        @foreach($section['notes'] as $note)
                            <li class="flex gap-2 text-sm text-muted ">
                                <x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-primary-text" />
                                <span>{{ $note }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-ui.card>
    @endforeach
</div>
