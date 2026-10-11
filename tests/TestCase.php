<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // RefreshDatabase migrates during parent::setUp(), so verify the
        // target database BEFORE booting the app to never touch real data.
        $database = (string) (getenv('DB_DATABASE') ?: '');

        if (! str_ends_with($database, '_testing')) {
            fwrite(STDERR, "Refusing to run tests: DB_DATABASE [{$database}] does not end in '_testing'.\n");
            exit(1);
        }

        parent::setUp();

        $this->withoutVite();
    }
}
