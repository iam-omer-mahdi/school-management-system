<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SchoolType;
use App\Models\School;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_schools_and_grades_seeded(): void
    {
        $this->seed(SchoolSeeder::class);

        $elementary = School::query()->where('type', SchoolType::Elementary->value)->firstOrFail();
        $middle = School::query()->where('type', SchoolType::Middle->value)->firstOrFail();
        $high = School::query()->where('type', SchoolType::High->value)->firstOrFail();

        $this->assertSame('Elementary', $elementary->name);
        $this->assertSame(SchoolType::Elementary, $elementary->type);
        $this->assertSame(SchoolType::Middle, $middle->type);
        $this->assertSame(SchoolType::High, $high->type);

        $this->assertSame([1, 2, 3, 4, 5, 6], $elementary->grades->pluck('number')->all());
        $this->assertSame([1, 2, 3], $middle->grades->pluck('number')->all());
        $this->assertSame([1, 2, 3], $high->grades->pluck('number')->all());
    }

    public function test_schools_page_lists_structure(): void
    {
        $this->seed(SchoolSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/schools')->assertOk();
    }

    /**
     * The label used to be a translation lookup on schools.code. It is now the
     * name the administrator typed (plan D8).
     */
    public function test_schools_are_shown_by_their_database_name(): void
    {
        $this->seed(SchoolSeeder::class);

        School::query()->where('type', SchoolType::Middle->value)->firstOrFail()
            ->update(['name' => 'Al Noor Middle School']);

        $this->actingAs(User::factory()->create())
            ->get(route('enrollments.index'))
            ->assertOk()
            ->assertSee('Al Noor Middle School');
    }
}
