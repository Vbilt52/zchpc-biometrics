<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\User;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonnelListNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_preserves_page_and_filters_and_never_accepts_redirect_urls(): void
    {
        $person = Personnel::create(['employee_no' => '101', 'name' => 'Example Person']);
        $group = AccessGroup::first();
        $list = ['page' => 3, 'search' => 'Example', 'access_group' => $group->id];
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get(route('personnel.edit', ['personnel' => $person, 'list' => $list]))->assertOk()->assertSee('name="list[page]" value="3"', false);
        $this->mock(PendingTerminalSyncService::class, function ($mock): void {
            $mock->shouldReceive('save')->once()->andReturn(['synced' => 0, 'pending' => 0]);
        });
        $this->put(route('personnel.update', $person), [
            'employee_no' => '101', 'name' => 'Example Person', 'user_type' => 'normal', 'valid_enabled' => 1,
            'begin_time' => '2026-01-01T00:00', 'end_time' => '2030-01-01T00:00', 'access_group_id' => $group->id,
            'list' => $list + ['redirect' => 'https://example.com'],
        ])->assertRedirect(route('personnel.index', $list));
    }

    public function test_single_and_bulk_removal_return_to_requested_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        foreach (['single', 'bulk'] as $type) {
            $person = Personnel::create(['employee_no' => $type, 'name' => 'Example Person']);
            $list = ['page' => 4, 'search' => 'Example'];
            $route = $type === 'single' ? route('personnel.destroy', $person) : route('personnel.bulk-destroy');
            $this->delete($route, ['list' => $list, 'ids' => [$person->id], 'confirm' => '1'])->assertRedirect(route('personnel.index', $list));
            $this->assertSoftDeleted($person);
        }
    }

    public function test_empty_last_page_falls_back_to_last_remaining_page_with_filters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Personnel::create(['employee_no' => '1', 'name' => 'Example Person']);
        $this->get(route('personnel.index', ['page' => 8, 'search' => 'Example']))->assertRedirect(route('personnel.index', ['page' => 1, 'search' => 'Example']));
    }
}
