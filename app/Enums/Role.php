<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The roles seeded on install (plan D3). Administrators may rename or delete
 * every role except Administrator, which is the way back in — so only
 * {@see Role::Administrator} may be relied on by name after setup.
 */
enum Role: string
{
    case Administrator = 'Administrator';
    case Registrar = 'Registrar';
    case Finance = 'Finance';
    case ReadOnly = 'Read-only';

    /**
     * The permissions this role starts life with (plan §4.2). Editable from
     * the Roles page afterwards.
     *
     * @return array<int, Permission>
     */
    public function presetPermissions(): array
    {
        return match ($this) {
            self::Administrator => [
                Permission::StudentsManage,
                Permission::EnrollmentsManage,
                Permission::FeesManage,
                Permission::PaymentsManage,
                Permission::ReportsView,
                Permission::SchoolsManage,
                Permission::StaffManage,
            ],
            self::Registrar => [
                Permission::StudentsManage,
                Permission::EnrollmentsManage,
                Permission::FeesView,
                Permission::PaymentsView,
                Permission::ReportsView,
            ],
            self::Finance => [
                Permission::StudentsView,
                Permission::EnrollmentsView,
                Permission::FeesManage,
                Permission::PaymentsManage,
                Permission::ReportsView,
            ],
            self::ReadOnly => [
                Permission::StudentsView,
                Permission::EnrollmentsView,
                Permission::FeesView,
                Permission::PaymentsView,
                Permission::ReportsView,
            ],
        };
    }
}
