<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SchoolType;
use Tests\TestCase;

class SchoolTypeTest extends TestCase
{
    public function test_each_type_knows_its_grade_numbers(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6], SchoolType::Elementary->gradeNumbers());
        $this->assertSame([1, 2, 3], SchoolType::Middle->gradeNumbers());
        $this->assertSame([1, 2, 3], SchoolType::High->gradeNumbers());
    }

    public function test_each_type_is_labelled_from_the_schools_language_file(): void
    {
        app()->setLocale('en');

        $this->assertSame('Elementary', SchoolType::Elementary->label());
        $this->assertSame('Middle', SchoolType::Middle->label());
        $this->assertSame('High', SchoolType::High->label());
    }
}
