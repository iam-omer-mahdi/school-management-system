<?php

use App\Actions\CreateDiscount;
use App\Actions\DeleteDiscount;
use App\Models\Discount;
use App\Support\Percent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $percent = '';

    public ?int $deletingId = null;

    public function create(): void
    {
        $this->reset(['name', 'percent']);
        $this->showForm = true;
    }

    public function save(CreateDiscount $action): void
    {
        $action->handle(['name' => $this->name, 'percent' => $this->percent]);

        $this->showForm = false;
        $this->reset(['name', 'percent']);
        unset($this->discounts, $this->parsedPercent);
        session()->flash('status', __('fees.discount_created'));
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function delete(DeleteDiscount $action): void
    {
        $action->handle((int) $this->deletingId);

        $this->deletingId = null;
        unset($this->discounts);
        session()->flash('status', __('fees.discount_deleted'));
    }

    #[Computed]
    public function parsedPercent(): ?int
    {
        try {
            return Percent::parse($this->percent);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Discount> */
    #[Computed]
    public function discounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Discount::query()
            ->select(['id', 'name', 'percent_bp'])
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('fees.discounts')">
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm" wire:click="create" :spinner="false">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('fees.discount_create') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if($showForm)
        <x-ui.card :title="__('fees.discount_create')">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('fees.discount_name')" for="discount-name" required :error="$errors->first('name')">
                        <x-ui.input id="discount-name" type="text" wire:model.blur="name" :error="$errors->first('name')" required autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('fees.percentage')" for="discount-percent" required :error="$errors->first('percent')" :hint="__('fees.percentage_hint')">
                        <x-ui.input id="discount-percent" type="number" min="1" max="100" step="0.01" wire:model.blur="percent" :error="$errors->first('percent')" inputmode="decimal" dir="ltr" autocomplete="off" class="font-mono" required />
                    </x-ui.field>
                </div>
                @if($this->parsedPercent)
                    <p class="text-sm text-gray-600 dark:text-gray-400"><x-ui.percent :bp="$this->parsedPercent" /></p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($this->discounts->isEmpty())
        <x-ui.empty-state :title="__('common.no_results')" :description="__('fees.no_discounts')" />
    @else
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('fees.discount_name') }}</th>
                <th>{{ __('fees.percentage') }}</th>
                <th>{{ __('common.actions') }}</th>
            </x-slot:head>
            @foreach($this->discounts as $discount)
                <tr wire:key="discount-{{ $discount->id }}">
                    <td class="font-medium text-gray-900 dark:text-white">{{ $discount->name }}</td>
                    <td><x-ui.percent :bp="$discount->percent_bp" /></td>
                    <td>
                        <x-ui.button variant="ghost" size="sm" wire:click="confirmDelete({{ $discount->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    @if($deletingId)
        <x-ui.modal :title="__('common.confirm_title')" close="$set('deletingId', null)">
            <p>{{ __('fees.confirm_delete_discount') }}</p>
            @if($errors->first('discount'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('discount') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete" loading-target="delete" wire:loading.attr="disabled">{{ __('common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
