<?php

declare(strict_types=1);

namespace Tests\Feature;

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

        $this->assertDatabaseHas('schools', ['code' => 'elementary']);
        $this->assertDatabaseHas('schools', ['code' => 'middle']);
        $this->assertDatabaseHas('schools', ['code' => 'high']);

        $this->assertEquals(6, School::where('code', 'elementary')->firstOrFail()->grades()->count());
        $this->assertEquals(3, School::where('code', 'middle')->firstOrFail()->grades()->count());
        $this->assertEquals(3, School::where('code', 'high')->firstOrFail()->grades()->count());
    }

    public function test_schools_page_lists_structure(): void
    {
        $this->seed(SchoolSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/schools')->assertOk();
    }
}
