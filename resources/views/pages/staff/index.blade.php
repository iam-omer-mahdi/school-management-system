<?php

use App\Actions\CreateStaff;
use App\Actions\DeleteRole;
use App\Actions\SaveRole;
use App\Actions\UpdateStaffAccess;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role as SpatieRole;

new #[Layout('layouts::app')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var array<int, string> */
    public array $newRoles = [];

    public ?int $editingId = null;

    /** @var array<int, string> */
    public array $editingRoles = [];

    public bool $editingActive = true;

    public bool $showRoleForm = false;

    public ?int $editingRoleId = null;

    public string $roleName = '';

    /** @var array<int, string> */
    public array $selectedPermissions = [];

    public ?int $deletingRoleId = null;

    public function mount(): void
    {
        Gate::authorize(Permission::StaffManage->value);
    }

    public function create(): void
    {
        $this->reset(['name', 'email', 'password', 'password_confirmation', 'editingId']);
        $this->newRoles = $this->defaultRoles();
        $this->showForm = true;
    }

    public function save(CreateStaff $action): void
    {
        $action->handle([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
            'roles' => $this->newRoles,
        ]);

        $this->reset(['name', 'email', 'password', 'password_confirmation', 'showForm']);
        unset($this->staff);
        session()->flash('status', __('staff.created'));
    }

    public function editAccess(int $id): void
    {
        $this->reset(['showForm', 'name', 'email', 'password', 'password_confirmation']);

        $user = User::query()->with('roles:id,name')->findOrFail($id);

        $this->editingId = $user->id;
        $this->editingRoles = $user->roles->pluck('name')->all();
        $this->editingActive = $user->is_active;
    }

    public function updateAccess(UpdateStaffAccess $action): void
    {
        $action->handle((int) $this->editingId, $this->editingRoles, $this->editingActive);

        $this->reset(['editingId', 'editingRoles', 'editingActive']);
        unset($this->staff);
        session()->flash('status', __('staff.updated'));
    }

    public function newRole(): void
    {
        $this->reset(['showForm', 'editingRoleId', 'deletingRoleId', 'roleName']);
        $this->selectedPermissions = [];
        $this->showRoleForm = true;
    }

    public function editRole(int $id): void
    {
        $this->reset(['showForm', 'deletingRoleId']);

        $role = SpatieRole::query()->with('permissions:id,name')->findOrFail($id);

        $this->editingRoleId = $role->id;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->all();
        $this->showRoleForm = true;
    }

    public function saveRole(SaveRole $action): void
    {
        $action->handle($this->editingRoleId, [
            'name' => $this->roleName,
            'permissions' => $this->selectedPermissions,
        ]);

        $this->reset(['showRoleForm', 'editingRoleId', 'roleName', 'selectedPermissions']);
        unset($this->roles);
        session()->flash('status', __('staff.role_saved'));
    }

    public function confirmDeleteRole(int $id): void
    {
        $this->reset(['showForm', 'showRoleForm', 'editingRoleId']);

        $this->deletingRoleId = $id;
    }

    public function deleteRole(DeleteRole $action): void
    {
        $action->handle((int) $this->deletingRoleId);

        $this->reset('deletingRoleId');
        unset($this->roles);
        session()->flash('status', __('staff.role_deleted'));
    }

    /**
     * Ticking Manage ticks View with it, so a role can never change data it
     * cannot read (plan D4).
     */
    public function updatedSelectedPermissions(): void
    {
        $this->selectedPermissions = Permission::expandValues($this->selectedPermissions);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    #[Computed]
    public function staff(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->select(['id', 'name', 'email', 'is_active'])
            ->with('roles:id,name')
            ->orderBy('name')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SpatieRole> */
    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return SpatieRole::query()
            ->select(['id', 'name'])
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Read-only is the safe default for a new account (plan §4.3); if an
     * administrator has deleted it, the form simply starts with nothing ticked.
     *
     * @return array<int, string>
     */
    private function defaultRoles(): array
    {
        return $this->roles->contains('name', Role::ReadOnly->value) ? [Role::ReadOnly->value] : [];
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header :title="__('staff.title')">
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm" wire:click="create" :spinner="false">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('staff.add') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <x-ui.alert type="success" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if($showForm)
        <x-ui.card :title="__('staff.add')" :description="__('staff.initial_password_hint')">
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.field :label="__('staff.name')" for="staff-name" required :error="$errors->first('name')">
                        <x-ui.input id="staff-name" type="text" wire:model.blur="name" :error="$errors->first('name')" required autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('staff.email')" for="staff-email" required :error="$errors->first('email')">
                        <x-ui.input id="staff-email" type="email" wire:model.blur="email" :error="$errors->first('email')" required autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field :label="__('staff.initial_password')" for="staff-password" required :error="$errors->first('password')">
                        <x-ui.input id="staff-password" type="password" wire:model.blur="password" :error="$errors->first('password')" required autocomplete="new-password" />
                    </x-ui.field>
                    <x-ui.field :label="__('staff.password_confirmation')" for="staff-password-confirmation" required :error="$errors->first('password_confirmation')">
                        <x-ui.input id="staff-password-confirmation" type="password" wire:model.blur="password_confirmation" :error="$errors->first('password_confirmation')" required autocomplete="new-password" />
                    </x-ui.field>
                </div>
                <x-ui.field :label="__('staff.roles')" required :error="$errors->first('roles')">
                    <div class="space-y-2">
                        @foreach($this->roles as $role)
                            <x-ui.checkbox wire:model="newRoles" value="{{ $role->name }}" :label="$role->name" />
                        @endforeach
                    </div>
                </x-ui.field>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="save" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.table>
        <x-slot:head>
            <th>{{ __('staff.name') }}</th>
            <th>{{ __('staff.email') }}</th>
            <th>{{ __('staff.roles') }}</th>
            <th>{{ __('staff.status') }}</th>
            <th>{{ __('common.actions') }}</th>
        </x-slot:head>
        @foreach($this->staff as $member)
            <tr wire:key="staff-{{ $member->id }}">
                <td class="font-medium text-gray-900 dark:text-white">
                    {{ $member->name }}
                    @if(auth()->id() === $member->id)
                        <span class="ms-1 text-xs text-gray-500 dark:text-gray-400">({{ __('staff.you') }})</span>
                    @endif
                </td>
                <td dir="ltr">{{ $member->email }}</td>
                <td>
                    <div class="flex flex-wrap gap-1">
                        @foreach($member->roles as $role)
                            <x-ui.badge wire:key="member-{{ $member->id }}-role-{{ $role->id }}" color="blue">{{ $role->name }}</x-ui.badge>
                        @endforeach
                    </div>
                </td>
                <td>
                    @if($member->is_active)
                        <x-ui.badge color="green">{{ __('staff.active') }}</x-ui.badge>
                    @else
                        <x-ui.badge color="gray">{{ __('staff.inactive') }}</x-ui.badge>
                    @endif
                </td>
                <td>
                    <x-ui.button variant="ghost" size="sm" wire:click="editAccess({{ $member->id }})" :spinner="false">{{ __('staff.edit_access') }}</x-ui.button>
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.card :title="__('staff.roles_title')" :description="__('staff.roles_hint')">
        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" wire:click="newRole" :spinner="false">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('staff.new_role') }}
            </x-ui.button>
        </x-slot:footer>
        <x-ui.table>
            <x-slot:head>
                <th>{{ __('staff.role_name') }}</th>
                <th>{{ __('staff.staff') }}</th>
                <th>{{ __('staff.permissions') }}</th>
                <th>{{ __('common.actions') }}</th>
            </x-slot:head>
            @foreach($this->roles as $role)
                <tr wire:key="role-{{ $role->id }}">
                    <td class="font-medium text-gray-900 dark:text-white">{{ $role->name }}</td>
                    <td>{{ trans_choice('staff.users_count', $role->users_count) }}</td>
                    <td>{{ trans_choice('staff.permissions_count', $role->permissions_count) }}</td>
                    <td>
                        <div class="flex gap-1">
                            <x-ui.button variant="ghost" size="sm" wire:click="editRole({{ $role->id }})" :spinner="false">{{ __('common.edit') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm" wire:click="confirmDeleteRole({{ $role->id }})" :spinner="false" class="text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">{{ __('common.delete') }}</x-ui.button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @if($showRoleForm)
        <x-ui.card :title="$editingRoleId ? __('staff.edit_role') : __('staff.new_role')">
            <form wire:submit="saveRole" class="space-y-4">
                <x-ui.field :label="__('staff.role_name')" for="role-name" required :error="$errors->first('name')">
                    <x-ui.input id="role-name" type="text" wire:model.blur="roleName" :error="$errors->first('name')" required autocomplete="off" />
                </x-ui.field>
                <x-ui.field :label="__('staff.permissions')" required :error="$errors->first('permissions')">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-start text-gray-500 dark:text-gray-400">
                                    <th class="py-2 text-start font-medium">{{ __('staff.module') }}</th>
                                    <th class="py-2 text-start font-medium">{{ __('staff.view') }}</th>
                                    <th class="py-2 text-start font-medium">{{ __('staff.manage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(Permission::matrix() as $module => $columns)
                                    <tr wire:key="permission-module-{{ $module }}" class="border-t border-gray-100 dark:border-gray-700">
                                        <td class="py-2">{{ __('staff.modules.'.$module) }}</td>
                                        @foreach(['view', 'manage'] as $ability)
                                            <td class="py-2">
                                                @if($columns[$ability])
                                                    <x-ui.checkbox wire:model.live="selectedPermissions" value="{{ $columns[$ability]->value }}" />
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.field>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary" loading-target="saveRole" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showRoleForm', false)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($editingId)
        <x-ui.modal :title="__('staff.edit_access_title')" close="$set('editingId', null)">
            <div class="space-y-4">
                <x-ui.field :label="__('staff.roles')" required :error="$errors->first('roles')">
                    <div class="space-y-2">
                        @foreach($this->roles as $role)
                            <x-ui.checkbox wire:model="editingRoles" value="{{ $role->name }}" :label="$role->name" />
                        @endforeach
                    </div>
                </x-ui.field>
                <x-ui.field :label="__('staff.status')" :error="$errors->first('active')">
                    <x-ui.checkbox :label="__('staff.active')" wire:model="editingActive" />
                </x-ui.field>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('editingId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="updateAccess" loading-target="updateAccess" wire:loading.attr="disabled">{{ __('common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($deletingRoleId)
        <x-ui.modal :title="__('common.confirm_title')" close="$set('deletingRoleId', null)">
            <p>{{ __('staff.confirm_delete_role') }}</p>
            @if($errors->first('role'))
                <div class="mt-3"><x-ui.alert type="error">{{ $errors->first('role') }}</x-ui.alert></div>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('deletingRoleId', null)" :spinner="false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteRole" loading-target="deleteRole" wire:loading.attr="disabled">{{ __('common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
