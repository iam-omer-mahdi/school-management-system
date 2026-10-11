<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Percent;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PercentTest extends TestCase
{
    public function test_parses_whole_and_fractional_percentages(): void
    {
        $this->assertSame(10000, Percent::parse('100'));
        $this->assertSame(1, Percent::parse('0.01'));
        $this->assertSame(1250, Percent::parse('12.5'));
    }

    public function test_parses_arabic_input(): void
    {
        $this->assertSame(1250, Percent::parse('١٢٫٥'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPercentages(): array
    {
        return [
            'above one hundred' => ['100.01'],
            'zero' => ['0'],
            'zero decimals' => ['0.00'],
            'negative' => ['-5'],
            'letters' => ['abc'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidPercentages')]
    public function test_rejects_out_of_range_percentages(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Percent::parse($value);
    }

    public function test_formats_with_decimals_only_when_non_zero(): void
    {
        $this->assertSame('10%', Percent::format(1000));
        $this->assertSame('12.5%', Percent::format(1250));
        $this->assertSame('0.01%', Percent::format(1));
        $this->assertSame('100%', Percent::format(10000));
    }

    public function test_percent_component_renders_bdi(): void
    {
        $html = Blade::render('<x-ui.percent :bp="1250" />');

        $this->assertStringContainsString('<bdi dir="ltr">', $html);
        $this->assertStringContainsString('12.5%', $html);
    }
}
