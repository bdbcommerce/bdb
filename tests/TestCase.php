<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Seeders (brand logos, article images) and uploads write to the public disk:
        // never let a test touch the real storage/app/public.
        Storage::fake('public');
    }
}
