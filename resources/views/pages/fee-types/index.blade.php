<?php

use App\Actions\CreateFeeType;
use App\Actions\DeleteFeeType;
use App\Models\FeeType;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public bool $is_mandatory = false;

    public ?int $deletingId = null;

    public function create(): void
    {
        $this->reset(['name', 'is_mandatory']);
        $this->showForm = true;
    }

    public function save(CreateFeeType $action): void
    {
        $action->handle(['name' => $this->name, 'is_mandatory' => $this->is_mandatory]);

        $this->showForm = false;
        $this->reset(['name', 'is_mandatory']);
        unset($this->types);
        session()->flash('status', __('fees.fee_type_created'));
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function delete(DeleteFeeType $action): void
    {
        $action->handle((int) $this->deletingId);

        $this->deletingId = null;
        unset($this->types);
        session()->flash('status', __('fees.fee_type_deleted'));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FeeType> */
    #[Computed]
    public function types(): \Illuminate\Database\Eloquent\Collection
    {
        return FeeType::query()
            ->select(['id', 'name', 'system_key', 'is_mandatory'])
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('fees.fee_types')">
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm" wire:click="create" :spinner="false">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('fees.fee_type_create') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if($showForm)
        <x-ui.card :title="__('fees.fee_type_create')">
            <form wire:submit="save" class="space-y-4">
                <x-ui.field :label="__('fees.name')" for="type-name" required :error="$errors->first('name')">
                    <x-ui.input id="type-name" type="text" wire:model.blur="name" :error="$errors->first('name')" required autocomplete="off" />
                </x-ui.field>
                <x-ui.checkbox :label="__('fees.is_mandatory')" wire:model.blur="is_mandatory" />
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($this->types->isEmpty())
        <x-ui.empty-state :title="__('common.no_results')" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('fees.name') }}</th>
                <th>{{ __('fees.is_mandatory') }}</th>
                <th>{{ __('common.actions') }}</th>
            </x-slot:head>
            @foreach($this->types as $type)
                <tr wire:key="type-{{ $type->id }}">
                    <td class="font-medium text-gray-900 dark:text-white">{{ $type->name }}</td>
                    <td>
                        @if($type->is_mandatory)
                            <x-ui.badge color="blue">{{ __('fees.mandatory') }}</x-ui.badge>
                        @else
                            <x-ui.badge color="gray">{{ __('fees.optional') }}</x-ui.badge>
                        @endif
                    </td>
                    <td>
                        @if(! $type->isTuition())
                            <x-ui.button variant="ghost" size="sm" wire:click="confirmDelete({{ $type->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    @if($deletingId)
        <x-ui.modal :title="__('common.confirm_title')" close="$set('deletingId', null)">
            <p>{{ __('fees.confirm_delete_fee_type') }}</p>
            @if($errors->first('fee_type'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('fee_type') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete" loading-target="delete" wire:loading.attr="disabled">{{ __('common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
