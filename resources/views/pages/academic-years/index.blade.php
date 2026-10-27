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

    /**
     * Keep the readonly generated name in sync when the start month changes.
     */
    public function updated(string $property): void
    {
        if ($property === 'form.starts_on') {
            $this->form->syncName();
        }
    }

    public function save(): void
    {
        $this->form->store();
        session()->flash('status', __('academic_years.created'));

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

<div class="space-y-5">
    <x-ui.page-header :title="__('academic_years.title')" :description="__('academic_years.open_note')">
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm" wire:click="create" :spinner="false">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('academic_years.create') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if($showForm)
        <x-ui.card :title="__('academic_years.create')">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('academic_years.starts_on')" for="year-starts" required :error="$errors->first('form.starts_on')">
                        <x-ui.input id="year-starts" type="text" wire:ignore data-month-picker="form.starts_on" :value="$form->starts_on" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" inputmode="numeric" autocomplete="off" :error="$errors->first('form.starts_on')" required />
                    </x-ui.field>
                    <x-ui.field :label="__('academic_years.ends_on')" for="year-ends" required :error="$errors->first('form.end_month')">
                        <x-ui.select id="year-ends" wire:model="form.end_month" :error="$errors->first('form.end_month')" required>
                            <option value="">—</option>
                            @foreach(\App\Livewire\Forms\AcademicYearForm::monthOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
                <x-ui.field :label="__('academic_years.name')" for="year-name">
                    <x-ui.input id="year-name" type="text" wire:model="form.name" readonly tabindex="-1" class="bg-surface-raised " />
                </x-ui.field>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($this->years->isEmpty() && ! $showForm)
        <x-ui.empty-state :title="__('common.no_results')" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('academic_years.name') }}</th>
                <th>{{ __('academic_years.starts_on') }}</th>
                <th>{{ __('academic_years.ends_on') }}</th>
                <th>{{ __('common.actions') }}</th>
            </x-slot:head>
            @foreach($this->years as $year)
                <tr wire:key="year-{{ $year->id }}">
                    <td class="font-medium text-ink ">{{ $year->name }}</td>
                    <td>{{ $year->starts_on?->format('n/Y') }}</td>
                    <td>{{ $year->ends_on?->format('n/Y') }}</td>
                    <td>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button variant="ghost" size="sm" wire:click="confirmDelete({{ $year->id }})" :spinner="false" class="text-negative hover:bg-negative-surface ">{{ __('common.delete') }}</x-ui.button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $this->years->links() }}</div>
    @endif

    @if($deletingId)
        <x-ui.modal :title="__('common.confirm_title')" close="$set('deletingId', null)">
            <p>{{ __('academic_years.confirm_delete') }}</p>
            @if($errors->first('delete'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('delete') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete" loading-target="delete" wire:loading.attr="disabled">{{ __('common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
