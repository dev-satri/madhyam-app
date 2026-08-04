<?php

namespace Tests\Feature\Livewire\Partials;

use App\Livewire\Partials\DriveBrowser;
use Livewire\Livewire;
use Tests\TestCase;

class DriveBrowserTest extends TestCase
{
    public function test_renders_successfully()
    {
        Livewire::test(DriveBrowser::class)
            ->assertStatus(200);
    }
}
