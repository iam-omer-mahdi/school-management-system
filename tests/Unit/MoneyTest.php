<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_plain_and_grouped_amounts(): void
    {
        $this->assertSame(125000, Money::fromDecimal('1250'));
        $this->assertSame(125050, Money::fromDecimal('1,250.50'));
        $this->assertSame(1, Money::fromDecimal('0.01'));
        $this->assertSame(125000, Money::fromDecimal(' 1250 '));
    }

    public function test_parses_arabic_digits_and_separators(): void
    {
        $this->assertSame(1250, Money::fromDecimal('١٢٫٥'));
        $this->assertSame(125050, Money::fromDecimal('١٬٢٥٠٫٥٠'));
    }

    public function test_accepts_maximum_and_rejects_above_it(): void
    {
        $this->assertSame(9_999_999_999_999, Money::fromDecimal('99999999999.99'));

        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('100000000000.00');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'negative' => ['-5'],
            'letters' => ['abc'],
            'mixed' => ['12a'],
            'three decimals' => ['12.345'],
            'multiple separators' => ['1.2.3'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal($value);
    }

    public function test_percent_of_rounds_half_up_to_the_piastre(): void
    {
        $this->assertSame(1, Money::percentOf(1, 5000));
        $this->assertSame(2, Money::percentOf(3, 5000));
        $this->assertSame(100000, Money::percentOf(100000, 10000));
        $this->assertSame(0, Money::percentOf(100000, 0));
    }

    public function test_percent_of_maximum_stays_exact(): void
    {
        $this->assertSame(Money::MAX_MINOR, Money::percentOf(Money::MAX_MINOR, 10000));
        $this->assertSame(1_000_000_000, Money::percentOf(Money::MAX_MINOR, 1));
    }

    public function test_format_uses_western_digits_and_hides_zero_cents(): void
    {
        app()->setLocale('en');

        $this->assertSame('1,250 SDG', Money::format(125000));
        $this->assertSame('1,250.50 SDG', Money::format(125050));
        $this->assertSame('0 SDG', Money::format(0));
    }

    public function test_format_keeps_the_sign_on_negative_amounts(): void
    {
        app()->setLocale('en');

        $this->assertSame('-1,250 SDG', Money::format(-125000));
        $this->assertSame('-1,250.50 SDG', Money::format(-125050));
        $this->assertSame('-0.50 SDG', Money::format(-50));
    }

    public function test_format_uses_arabic_label(): void
    {
        app()->setLocale('ar');

        $this->assertSame('1,250 ج.س', Money::format(125000));
    }

    public function test_money_component_renders_bdi_in_both_languages(): void
    {
        foreach (['en' => '1,250 SDG', 'ar' => '1,250 ج.س'] as $locale => $expected) {
            app()->setLocale($locale);

            $html = Blade::render('<x-ui.money :minor="125000" />');

            $this->assertStringContainsString('<bdi dir="ltr">', $html);
            $this->assertStringContainsString($expected, $html);
        }
    }
}
