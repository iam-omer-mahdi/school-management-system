<?php

use App\Livewire\Forms\AcademicYearForm;
use App\Models\AcademicYear;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] class extends Component
{
    use WithPagination;

    public AcademicYearForm $form;

    public bool $showForm = false;

    public ?int $deletingId = null;

    public function create(): void
    {
        $this->form->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $year = AcademicYear::query()->findOrFail($id);
        $this->form->fillFrom($year);
        $this->showForm = true;
    }

    public function save(): void
    {
        if ($this->form->editingId) {
            $year = AcademicYear::query()->findOrFail($this->form->editingId);
            $this->form->update($year);
            session()->flash('status', __('academic_years.updated'));
        } else {
            $this->form->store();
            session()->flash('status', __('academic_years.created'));
        }

        $this->showForm = false;
        $this->form->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function delete(): void
    {
        $year = AcademicYear::query()->findOrFail($this->deletingId);

        if ($year->enrollments()->exists()) {
            $this->addError('delete', __('academic_years.cannot_delete_with_enrollments'));

            return;
        }

        $year->delete();
        $this->deletingId = null;
        session()->flash('status', __('academic_years.deleted'));
    }

    #[Computed]
    public function years(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return AcademicYear::query()
            ->select(['id', 'name', 'starts_on', 'ends_on'])
            ->withCount('enrollments')
            ->orderBy('name')
            ->paginate(15);
    }
};
?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('academic_years.title') }}</h1>
        <button wire:click="create" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">{{ __('academic_years.create') }}</button>
    </div>
    <p class="text-sm text-gray-600">{{ __('academic_years.open_note') }}</p>

    @if(session('status'))
        <p class="rounded bg-green-100 p-2 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    @if($showForm)
        <form wire:submit="save" class="space-y-3 rounded bg-white p-4 shadow">
            <x-field :label="__('academic_years.name')" :error="$errors->first('form.name')">
                <input type="text" wire:model.blur="form.name" class="w-full rounded border-gray-300 px-3 py-2" />
            </x-field>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-field :label="__('academic_years.starts_on')" :error="$errors->first('form.starts_on')">
                    <input type="date" wire:model.blur="form.starts_on" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
                <x-field :label="__('academic_years.ends_on')" :error="$errors->first('form.ends_on')">
                    <input type="date" wire:model.blur="form.ends_on" class="w-full rounded border-gray-300 px-3 py-2" />
                </x-field>
            </div>
            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">{{ __('common.save') }}</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded border px-4 py-2 hover:bg-gray-100">{{ __('common.cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded bg-white shadow">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-start">
                    <th class="p-2 text-start">{{ __('academic_years.name') }}</th>
                    <th class="p-2 text-start">{{ __('academic_years.starts_on') }}</th>
                    <th class="p-2 text-start">{{ __('academic_years.ends_on') }}</th>
                    <th class="p-2 text-start">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->years as $year)
                    <tr wire:key="year-{{ $year->id }}" class="border-b">
                        <td class="p-2">{{ $year->name }}</td>
                        <td class="p-2">{{ $year->starts_on?->format('Y-m-d') }}</td>
                        <td class="p-2">{{ $year->ends_on?->format('Y-m-d') }}</td>
                        <td class="p-2 flex gap-2">
                            <button wire:click="edit({{ $year->id }})" class="text-blue-700 hover:underline">{{ __('common.edit') }}</button>
                            <button wire:click="confirmDelete({{ $year->id }})" class="text-red-700 hover:underline">{{ __('common.delete') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $this->years->links() }}

    @if($deletingId)
        <div class="rounded bg-white p-4 shadow">
            <p class="mb-3 text-sm">{{ __('common.confirm_title') }}</p>
            @error('delete')<p class="mb-2 text-sm text-red-700">{{ $message }}</p>@enderror
            <div class="flex gap-2">
                <button wire:click="delete" wire:loading.attr="disabled" class="rounded bg-red-700 px-4 py-2 text-white">{{ __('common.delete') }}</button>
                <button wire:click="$set('deletingId', null)" class="rounded border px-4 py-2">{{ __('common.cancel') }}</button>
            </div>
        </div>
    @endif
</div>
