<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiInputPasswordToggleTest extends TestCase
{
    public function test_password_inputs_render_a_visibility_toggle(): void
    {
        $html = Blade::render('<x-ui.input type="password" id="secret" />');

        $this->assertStringContainsString('data-password-toggle', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('pe-11', $html);
    }

    public function test_other_inputs_do_not_render_a_toggle(): void
    {
        $html = Blade::render('<x-ui.input type="text" id="plain" />');

        $this->assertStringNotContainsString('data-password-toggle', $html);
    }
}
