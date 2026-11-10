<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveDashboardAndSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_returns_categorized_results(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $person = Personnel::create([
            'employee_no' => 'EMP-001',
            'name' => 'John Chimuti',
            'department' => 'Engineering',
            'status' => 'active',
        ]);

        $terminal = Terminal::create([
            'name' => 'Main Gate Reader',
            'ip' => '192.168.60.40',
            'port' => 80,
            'username' => 'admin',
            'password' => '12345',
            'status' => 'online',
        ]);

        AccessEvent::create([
            'event_key' => 'EVT-TEST-001',
            'terminal_id' => $terminal->id,
            'occurred_at' => now(),
            'person_name' => 'John Chimuti',
            'employee_no' => 'EMP-001',
            'major' => 1,
            'minor' => 1,
            'raw_payload' => ['doorNo' => 1],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('search.live', ['q' => 'Chimuti']));

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('personnel.0.title', 'John Chimuti')
            ->assertJsonPath('events.0.title', 'John Chimuti');

        $deviceResponse = $this->actingAs($admin)
            ->getJson(route('search.live', ['q' => 'Main Gate']));

        $deviceResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('devices.0.title', 'Main Gate Reader');
    }

    public function test_dashboard_live_data_returns_expected_kpis_and_chart(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live-data'));

        $response->assertOk()
            ->assertJsonStructure([
                'totalTerminals',
                'onlineTerminals',
                'offlineTerminals',
                'controlledDoors',
                'totalPersons',
                'eventsTodayCount',
                'recentEvents',
                'chartDays',
                'chartMax',
                'formattedDateTime',
                'terminalProgress',
                'healthPercent',
                'healthTitle',
                'healthSubtitle',
            ]);
    }
}
