<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    public function test_normalize_trims_and_collapses_whitespace(): void
    {
        $this->assertSame('Bus Fees', TextNormalizer::normalize('  Bus   Fees  '));
    }

    public function test_normalize_turns_nbsp_into_space(): void
    {
        $this->assertSame('Bus Fees', TextNormalizer::normalize("Bus\u{00A0}Fees"));
    }

    public function test_normalize_removes_zero_width_and_bidi_controls(): void
    {
        $this->assertSame(
            'Bus',
            TextNormalizer::normalize("B\u{200B}u\u{200C}s\u{200D}\u{200E}\u{200F}\u{202A}\u{202E}\u{2066}\u{2069}\u{FEFF}")
        );
    }

    public function test_comparison_key_folds_arabic_variants(): void
    {
        $this->assertSame(TextNormalizer::comparisonKey('أحمد'), TextNormalizer::comparisonKey('احمد'));
        $this->assertSame(TextNormalizer::comparisonKey('إحسان'), TextNormalizer::comparisonKey('احسان'));
        $this->assertSame(TextNormalizer::comparisonKey('مدرسة'), TextNormalizer::comparisonKey('مدرسه'));
        $this->assertSame(TextNormalizer::comparisonKey('علي'), TextNormalizer::comparisonKey('على'));
        $this->assertSame(TextNormalizer::comparisonKey('مَدرسة'), TextNormalizer::comparisonKey('مدرسة'));
        $this->assertSame(TextNormalizer::comparisonKey('مـدرسة'), TextNormalizer::comparisonKey('مدرسة'));
    }

    public function test_comparison_key_is_case_insensitive(): void
    {
        $this->assertSame(TextNormalizer::comparisonKey('Bus'), TextNormalizer::comparisonKey('bus'));
    }

    public function test_comparison_key_keeps_distinct_names_distinct(): void
    {
        $this->assertNotSame(TextNormalizer::comparisonKey('Bus'), TextNormalizer::comparisonKey('Books'));
        $this->assertNotSame(TextNormalizer::comparisonKey('مدرسة'), TextNormalizer::comparisonKey('مدرس'));
    }
}
