<x-ui.field :label="__('staff.permissions')" required :error="$errors->first('permissions')">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-start text-muted ">
                    <th class="py-2 text-start font-medium">{{ __('staff.module') }}</th>
                    <th class="py-2 text-start font-medium">{{ __('staff.view') }}</th>
                    <th class="py-2 text-start font-medium">{{ __('staff.manage') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach(\App\Enums\Permission::matrix() as $module => $columns)
                    <tr wire:key="permission-module-{{ $module }}" class="border-t border-line ">
                        <td class="py-2">{{ __('staff.modules.'.$module) }}</td>
                        @foreach(['view', 'manage'] as $ability)
                            <td class="py-2">
                                @if($columns[$ability])
                                    <x-ui.checkbox wire:model.live="selectedPermissions" value="{{ $columns[$ability]->value }}" :aria-label="__('staff.modules.'.$module).' — '.__('staff.'.$ability)" />
                                @else
                                    <span class="text-subtle">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-ui.field>
