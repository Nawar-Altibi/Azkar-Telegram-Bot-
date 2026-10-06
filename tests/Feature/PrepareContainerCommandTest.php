<?php

namespace Tests\Feature;

use App\Models\AdhkarCollection;
use Tests\TestCase;

class PrepareContainerCommandTest extends TestCase
{
    public function test_prepare_migrates_and_syncs_content_in_one_process(): void
    {
        $this->artisan('adhkar:prepare')
            ->expectsOutputToContain('Container prepared: migrate')
            ->assertSuccessful();

        $this->assertTrue(AdhkarCollection::query()->exists());
    }
}
