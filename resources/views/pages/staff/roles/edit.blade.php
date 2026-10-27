<?php

use App\Actions\SaveRole;
use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role as SpatieRole;

new #[Layout('layouts::app')] class extends Component
{
    #[Locked]
    public int $roleId;

    public string $roleName = '';

    /** @var array<int, string> */
    public array $selectedPermissions = [];

    public function mount(SpatieRole $role): void
    {
        Gate::authorize(Permission::StaffManage->value);

        $this->roleId = $role->id;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions()->pluck('name')->all();
    }

    public function save(SaveRole $action): void
    {
        $action->handle($this->roleId, [
            'name' => $this->roleName,
            'permissions' => $this->selectedPermissions,
        ]);

        session()->flash('status', __('staff.role_saved'));

        $this->redirectRoute('staff.index', navigate: true);
    }

    /**
     * Ticking Manage ticks View with it, so a role can never change data it
     * cannot read (plan D4).
     */
    public function updatedSelectedPermissions(): void
    {
        $this->selectedPermissions = Permission::expandValues($this->selectedPermissions);
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('staff.edit_role')" :backUrl="route('staff.index')" />

    <x-ui.card>
        <form wire:submit="save" class="space-y-4">
            <x-ui.field :label="__('staff.role_name')" for="role-name" required :error="$errors->first('name')">
                <x-ui.input id="role-name" type="text" wire:model.blur="roleName" :error="$errors->first('name')" required autocomplete="off" />
            </x-ui.field>

            <x-staff.permission-matrix />

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                <x-ui.button href="{{ route('staff.index') }}" wire:navigate variant="secondary" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
