<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\EnrollmentDiscount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiscountsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_discounts_page_requires_auth(): void
    {
        $this->get(route('discounts.index'))->assertRedirect(route('login'));
    }

    public function test_sidebar_links_discounts_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('discounts.index'));
    }

    public function test_discount_create_and_delete_flow(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::discounts.index')
            ->set('name', 'Sibling')
            ->set('percent', '12.5')
            ->call('save')
            ->assertHasNoErrors();

        $discount = Discount::query()->where('name', 'Sibling')->firstOrFail();

        $this->assertSame(1250, $discount->percent_bp);

        Livewire::test('pages::discounts.index')
            ->call('confirmDelete', $discount->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertFalse(Discount::query()->where('name', 'Sibling')->exists());
    }

    public function test_discount_duplicate_and_invalid_percent_show_errors(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::discounts.index')
            ->set('name', 'Sibling')
            ->set('percent', '10')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::discounts.index')
            ->set('name', 'sibling')
            ->set('percent', '10')
            ->call('save')
            ->assertHasErrors(['name']);

        Livewire::test('pages::discounts.index')
            ->set('name', 'Other')
            ->set('percent', 'abc')
            ->call('save')
            ->assertHasErrors(['percent']);
    }

    public function test_used_discount_cannot_be_deleted_from_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::discounts.index')
            ->set('name', 'Sibling')
            ->set('percent', '10')
            ->call('save')
            ->assertHasNoErrors();

        $discount = Discount::query()->where('name', 'Sibling')->firstOrFail();

        EnrollmentDiscount::factory()->create(['discount_id' => $discount->id]);

        Livewire::test('pages::discounts.index')
            ->call('confirmDelete', $discount->id)
            ->call('delete')
            ->assertHasErrors(['discount']);

        $this->assertTrue(Discount::query()->whereKey($discount->id)->exists());
    }

    public function test_empty_catalog_shows_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::discounts.index')
            ->assertSee(__('fees.no_discounts'));
    }
}
