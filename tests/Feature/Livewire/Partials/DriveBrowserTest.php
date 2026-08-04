<?php

namespace Tests\Feature\Livewire\Partials;

use App\Livewire\Partials\DriveBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DriveBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully()
    {
        Livewire::test(DriveBrowser::class)
            ->assertStatus(200);
    }
}
