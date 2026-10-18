<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SchoolType;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeFee;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    public function test_creating_a_school_creates_its_grades(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('create')
            ->set('name', 'Al Noor Prep')
            ->set('type', SchoolType::Middle->value)
            ->call('save')
            ->assertHasNoErrors();

        $school = School::query()->where('name', 'Al Noor Prep')->firstOrFail();

        $this->assertSame(SchoolType::Middle, $school->type);
        $this->assertSame([1, 2, 3], $school->grades->pluck('number')->all());
    }

    public function test_a_school_name_must_be_unique(): void
    {
        $this->seed(SchoolSeeder::class);
        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('create')
            ->set('name', 'Elementary')
            ->set('type', SchoolType::Elementary->value)
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(3, School::query()->count());
    }

    public function test_an_unknown_school_type_is_rejected(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('create')
            ->set('name', 'Al Noor College')
            ->set('type', 'college')
            ->call('save')
            ->assertHasErrors('type');

        $this->assertFalse(School::query()->where('name', 'Al Noor College')->exists());
    }

    public function test_renaming_a_school_keeps_its_type_and_grades(): void
    {
        $this->seed(SchoolSeeder::class);
        $school = School::query()->where('type', SchoolType::Middle->value)->firstOrFail();
        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('editName', $school->id)
            ->set('editingName', 'Al Noor Middle School')
            ->call('rename')
            ->assertHasNoErrors();

        $school->refresh();

        $this->assertSame('Al Noor Middle School', $school->name);
        $this->assertSame(SchoolType::Middle, $school->type);
        $this->assertSame([1, 2, 3], $school->grades->pluck('number')->all());
    }

    public function test_a_school_that_holds_no_student_data_can_be_deleted(): void
    {
        $this->seed(SchoolSeeder::class);
        $school = School::query()->where('type', SchoolType::High->value)->firstOrFail();
        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('confirmDelete', $school->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertFalse(School::query()->whereKey($school->id)->exists());
    }

    public function test_a_school_with_an_enrollment_cannot_be_deleted(): void
    {
        $school = School::factory()->create(['name' => 'In Use']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);

        Enrollment::factory()->create([
            'student_id' => Student::factory()->create(),
            'academic_year_id' => AcademicYear::factory()->create(['name' => '2030/2031'])->id,
            'grade_id' => $grade->id,
        ]);

        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('confirmDelete', $school->id)
            ->call('delete')
            ->assertHasErrors('school');

        $this->assertTrue(School::query()->whereKey($school->id)->exists());
    }

    public function test_a_school_with_a_grade_fee_cannot_be_deleted(): void
    {
        $school = School::factory()->create(['name' => 'Charged']);
        $grade = Grade::factory()->create(['school_id' => $school->id, 'number' => 1]);

        GradeFee::factory()->create(['grade_id' => $grade->id]);

        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->call('confirmDelete', $school->id)
            ->call('delete')
            ->assertHasErrors('school');

        $this->assertTrue(School::query()->whereKey($school->id)->exists());
    }

    public function test_the_school_controls_are_hidden_from_users_without_schools_manage(): void
    {
        $this->seed(SchoolSeeder::class);
        $this->actingAs(User::factory()->withRole()->create());

        Livewire::test('pages::schools.index')
            ->assertDontSee(__('schools.add'))
            ->assertDontSee(__('schools.rename'));
    }

    public function test_the_school_controls_are_shown_to_administrators(): void
    {
        $this->seed(SchoolSeeder::class);
        $this->actingAs($this->administrator());

        Livewire::test('pages::schools.index')
            ->assertSee(__('schools.add'))
            ->assertSee(__('schools.rename'));
    }

    public function test_a_user_without_schools_manage_cannot_add_a_school(): void
    {
        $this->actingAs(User::factory()->withRole()->create());

        Livewire::test('pages::schools.index')
            ->call('create')
            ->set('name', 'Sneaky School')
            ->set('type', SchoolType::Elementary->value)
            ->call('save')
            ->assertStatus(403);

        $this->assertFalse(School::query()->where('name', 'Sneaky School')->exists());
    }

    public function test_a_user_without_schools_manage_cannot_delete_a_school(): void
    {
        $this->seed(SchoolSeeder::class);
        $school = School::query()->where('type', SchoolType::High->value)->firstOrFail();
        $this->actingAs(User::factory()->withRole()->create());

        Livewire::test('pages::schools.index')
            ->call('confirmDelete', $school->id)
            ->call('delete')
            ->assertStatus(403);

        $this->assertTrue(School::query()->whereKey($school->id)->exists());
    }

    public function test_a_user_without_schools_manage_cannot_rename_a_school(): void
    {
        $this->seed(SchoolSeeder::class);
        $school = School::query()->where('type', SchoolType::High->value)->firstOrFail();
        $this->actingAs(User::factory()->withRole()->create());

        Livewire::test('pages::schools.index')
            ->call('editName', $school->id)
            ->set('editingName', 'Renamed')
            ->call('rename')
            ->assertStatus(403);

        $this->assertSame('High', $school->refresh()->name);
    }
}
