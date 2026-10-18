<?php

use App\Actions\CreateSchool;
use App\Actions\DeleteSchool;
use App\Actions\RenameSchool;
use App\Enums\SchoolType;
use App\Models\School;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $type = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public ?int $deletingId = null;

    public function create(): void
    {
        $this->reset(['name', 'type', 'editingId', 'editingName', 'deletingId']);
        $this->showForm = true;
    }

    public function save(CreateSchool $action): void
    {
        $action->handle($this->name, $this->type);

        $this->reset(['name', 'type', 'showForm']);
        unset($this->schools);
        session()->flash('status', __('schools.created'));
    }

    public function editName(int $id): void
    {
        $this->reset(['showForm', 'deletingId']);
        $this->editingId = $id;
        $this->editingName = (string) School::query()->whereKey($id)->value('name');
    }

    public function rename(RenameSchool $action): void
    {
        $action->handle((int) $this->editingId, $this->editingName);

        $this->reset(['editingId', 'editingName']);
        unset($this->schools);
        session()->flash('status', __('schools.renamed'));
    }

    public function confirmDelete(int $id): void
    {
        $this->reset(['showForm', 'editingId', 'editingName']);
        $this->deletingId = $id;
    }

    public function delete(DeleteSchool $action): void
    {
        $action->handle((int) $this->deletingId);

        $this->reset('deletingId');
        unset($this->schools);
        session()->flash('status', __('schools.deleted'));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, School> */
    #[Computed]
    public function schools(): \Illuminate\Database\Eloquent\Collection
    {
        return School::query()
            ->select(['id', 'name', 'type'])
            ->with(['grades:id,school_id,number'])
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, SchoolType> */
    #[Computed]
    public function types(): array
    {
        return SchoolType::cases();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('schools.title')">
        @can(\App\Enums\Permission::SchoolsManage->value)
            <x-slot:actions>
                <x-ui.button variant="primary" size="sm" wire:click="create" :spinner="false">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('schools.add') }}
                </x-ui.button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if($showForm)
        <x-ui.card :title="__('schools.add')">
            <form wire:submit="save" class="space-y-4">
                <x-ui.field :label="__('schools.name')" for="school-name" required :error="$errors->first('name')">
                    <x-ui.input id="school-name" type="text" wire:model.blur="name" :error="$errors->first('name')" required autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('schools.type')" for="school-type" required :error="$errors->first('type')" :hint="__('schools.type_locked')">
                    <x-ui.select id="school-type" wire:model.blur="type" :error="$errors->first('type')" required>
                        <option value="">—</option>
                        @foreach($this->types as $schoolType)
                            <option value="{{ $schoolType->value }}">{{ $schoolType->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach($this->schools as $school)
            <x-ui.card wire:key="school-{{ $school->id }}">
                <x-slot:header>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">
                                <x-ui.icon name="cap" class="h-5 w-5" />
                            </span>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $school->name }}</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $school->type->label() }}</p>
                            </div>
                        </div>
                        @can(\App\Enums\Permission::SchoolsManage->value)
                            <div class="flex shrink-0 gap-1">
                                <x-ui.button variant="ghost" size="sm" wire:click="editName({{ $school->id }})" :spinner="false">{{ __('schools.rename') }}</x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="confirmDelete({{ $school->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                            </div>
                        @endcan
                    </div>
                </x-slot:header>
                <ul class="flex flex-wrap gap-1.5">
                    @foreach($school->grades as $grade)
                        <li wire:key="grade-{{ $grade->id }}">
                            <x-ui.badge color="gray">{{ __('schools.grade_number', ['number' => $grade->number]) }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach
    </div>

    @if($editingId)
        <x-ui.modal :title="__('schools.rename_title')" close="$set('editingId', null)">
            <x-ui.field :label="__('schools.name')" for="school-editing-name" required :error="$errors->first('name')">
                <x-ui.input id="school-editing-name" type="text" wire:model.blur="editingName" :error="$errors->first('name')" required autocomplete="off" />
            </x-ui.field>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('editingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="rename" loading-target="rename" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($deletingId)
        <x-ui.modal :title="__('common.confirm_title')" close="$set('deletingId', null)">
            <p>{{ __('schools.confirm_delete') }}</p>
            @if($errors->first('school'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('school') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete" loading-target="delete" wire:loading.attr="disabled">{{ __('common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
