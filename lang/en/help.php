<?php

declare(strict_types=1);

return [
    'title' => 'How the system works',
    'intro' => 'A short guide to every part of the system, in the order a school year usually runs. Anyone who can sign in can read this page; what you are allowed to change still depends on your role.',

    'sections' => [
        'dashboard' => [
            'title' => 'Dashboard',
            'intro' => 'The first thing you see after signing in: the totals for the current academic year.',
            'steps' => [],
            'notes' => [
                'The numbers are built from the enrollments and payments recorded in the system — nothing is entered on this page.',
            ],
        ],

        'schools' => [
            'title' => 'Schools and grades',
            'intro' => 'A school is created once, and its grades are generated from its type.',
            'steps' => [
                'Open Schools.',
                'Add a school: type the name and choose the type — Elementary, Middle or High.',
                'Its grades are created with it: Elementary has grades 1 to 6, Middle and High have 1 to 3.',
                'Rename a school when its name changes; the grades stay as they are.',
            ],
            'notes' => [
                'The type is fixed at creation, because grades are numbered from it. A wrong type means a new school.',
                'Only an administrator can add, rename or delete a school.',
                'A school that has enrollments or fee amounts set cannot be deleted.',
                'School names must be unique.',
            ],
        ],

        'academic_years' => [
            'title' => 'Academic years',
            'intro' => 'Every enrollment, fee amount and payment belongs to an academic year, so the year has to exist before students are enrolled in it.',
            'steps' => [
                'Open Academic years.',
                'Create a year: choose its start month and end month. The name is generated from them.',
                'Enroll students into it from their pages.',
            ],
            'notes' => [
                'Creating a new year does not close older years; both stay usable.',
                'A year with enrollments in it cannot be deleted.',
            ],
        ],

        'students' => [
            'title' => 'Students',
            'intro' => 'The student record holds the identity and contact details that appear on receipts and reports.',
            'steps' => [
                'Open Students and search by name, number or phone.',
                'Add a student: name, guardian name and phone, address, date of birth, blood type and the rest.',
                'Each student gets a number, used on receipts and reports.',
                'Archive a student who has left; restore them if they come back.',
            ],
            'notes' => [
                'Archiving keeps the history: enrollments, charges and payments are untouched.',
                'Archiving is refused while the student has an active enrollment in the latest year — end that enrollment first.',
                'An archived student is hidden from the active list but still findable in the archived filter.',
            ],
        ],

        'enrollment' => [
            'title' => 'Enrollment',
            'intro' => 'An enrollment ties one student to one school, one grade and one academic year. Charges, discounts and payments all hang off it.',
            'steps' => [
                'Open the student and choose Enroll.',
                'Pick the school, grade and academic year.',
                'The mandatory fees set for that grade and year are applied immediately.',
                'Add optional fees and apply a discount from the enrollment page.',
                'Change the status when the student\'s situation changes — active, suspended, transferred, expelled or left.',
            ],
            'notes' => [
                'A student cannot be enrolled twice in the same grade and year.',
                'Active and suspended enrollments receive new mandatory fees; transferred, expelled and left are treated as ended.',
                'An enrollment already paid into is never deleted — change its status instead.',
            ],
        ],

        'fees' => [
            'title' => 'Fees and discounts',
            'intro' => 'Fee types are the catalogue (tuition, transport, books). The schedule sets each fee amount per grade and year. Discounts are percentages off those amounts.',
            'steps' => [
                'Create the fee types, marking each one mandatory or optional.',
                'Open Fee schedule, choose a grade and a year, and set the amount for each fee type.',
                'Create the discounts you offer in the discount catalogue.',
                'Apply a discount, add an optional fee or remove a charge from the enrollment itself.',
            ],
            'notes' => [
                'Mandatory fees are applied by the system; optional ones are added by hand when the student takes them.',
                'A discount replaces any discount already on the enrollment.',
                'Managing fees is separate from viewing them — someone who only reads records cannot change an amount.',
            ],
        ],

        'payments' => [
            'title' => 'Payments and receipts',
            'intro' => 'Payments are recorded against the enrollment, and every payment can be printed as a receipt.',
            'steps' => [
                'Open the enrollment and choose Add payment.',
                'Enter the amount, the date and the method — cash or bank transfer. Bank transfers also need the transaction number.',
                'Print the receipt for the payer.',
                'Void a payment that was recorded by mistake, giving a reason.',
            ],
            'notes' => [
                'A payment can never be larger than what is still owed.',
                'The same bank transaction number cannot be used twice.',
                'Voiding does not erase the record: it stays for the audit trail and drops out of the totals.',
            ],
        ],

        'refunds' => [
            'title' => 'Refunds and voids',
            'intro' => 'A refund gives money back. It is recorded against the enrollment, next to the payments.',
            'steps' => [
                'Open the enrollment and record the refund: amount, date, method and the reason.',
                'Void a refund recorded by mistake, giving a reason.',
            ],
            'notes' => [
                'Money can only be refunded up to what is left after previous refunds.',
                'Refunds count against collections in the reports, so the net paid stays honest.',
            ],
        ],

        'reports' => [
            'title' => 'Reports',
            'intro' => 'The reports page turns the year\'s records into collections, what is still owed, discounts, refunds and statements.',
            'steps' => [
                'Open Reports.',
                'Choose the filters — school, grade, fee type, status and dates.',
                'Read the sections: year summary, collections, outstanding call list, discounts, forgone by leavers, refunds and the student statement.',
                'Export to Excel or print. Use the landscape print for the wide tables.',
            ],
            'notes' => [
                'Voided transactions are listed separately and left out of the totals.',
                'A read-only account can open the reports.',
            ],
        ],

        'staff_roles' => [
            'title' => 'Staff and roles',
            'intro' => 'An administrator creates staff accounts and sets the first password. Roles decide what a person can see and change, and one person may hold more than one role.',
            'steps' => [
                'Open Staff.',
                'Add the member: name, email, an initial password and their roles.',
                'Hand the password over in person.',
                'The member signs in with it and is asked to choose their own password and set three security questions.',
                'Edit access later to change roles or deactivate the account.',
                'Manage roles: create a role, rename it, tick its permissions or delete it.',
            ],
            'notes' => [
                'What a person can do is the sum of the roles they hold.',
                'Ticking Manage ticks View with it, so nobody changes what they cannot read.',
                'The Administrator role keeps its name and its access to staff and roles — it is the way back in.',
                'A role that anyone holds cannot be deleted; move those people to another role first.',
                'A deactivated account cannot sign in, but its records stay.',
                'Unusual access is a new role, not a per-person exception.',
            ],
        ],

        'account' => [
            'title' => 'Your account and security',
            'intro' => 'Your own password, recovery questions, email address, language and theme.',
            'steps' => [
                'Open Settings.',
                'Change password: enter the current password, then the new one twice.',
                'Set the three security answers — you are asked for all three at your first sign-in.',
                'Change your email address by confirming a code sent to the new address.',
                'Switch the display language between Arabic and English, and the theme between light and dark.',
            ],
            'notes' => [
                'Answers are stored hashed and are the offline way back into your account. Treat them like a password.',
                'The notice that your password was set by an administrator only appears after you sign in, never on the login page.',
            ],
        ],
    ],
];
