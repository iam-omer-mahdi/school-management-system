<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every ability in the app, in one place. Roles are seeded from these and the
 * Roles page writes back to them, so there is no second permission list in
 * Blade or config.
 */
enum Permission: string
{
    case StudentsView = 'students.view';
    case StudentsManage = 'students.manage';

    case EnrollmentsView = 'enrollments.view';
    case EnrollmentsManage = 'enrollments.manage';

    case FeesView = 'fees.view';
    case FeesManage = 'fees.manage';

    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';

    case ReportsView = 'reports.view';

    case SchoolsManage = 'schools.manage';

    case StaffManage = 'staff.manage';

    /**
     * The module view a `manage` permission implies, or null when the
     * permission is already as narrow as it gets.
     */
    public function impliedView(): ?self
    {
        return match ($this) {
            self::StudentsManage => self::StudentsView,
            self::EnrollmentsManage => self::EnrollmentsView,
            self::FeesManage => self::FeesView,
            self::PaymentsManage => self::PaymentsView,
            default => null,
        };
    }

    /**
     * Expand a set of permissions with the views they imply, so nobody can
     * hold Manage without View (the Roles page must not offer it either).
     *
     * @param  array<int, self>  $permissions
     * @return array<int, self>
     */
    public static function withImpliedViews(array $permissions): array
    {
        $expanded = [];

        foreach ($permissions as $permission) {
            $expanded[$permission->value] = $permission;

            $view = $permission->impliedView();

            if ($view !== null) {
                $expanded[$view->value] = $view;
            }
        }

        return array_values($expanded);
    }
}
