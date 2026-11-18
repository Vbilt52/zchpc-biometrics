<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\TerminalEventSync;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_feed_requires_hr_permission(): void
    {
        $this->getJson(route('dashboard.hr.activity'))->assertUnauthorized();
        $this->actingAs(User::factory()->viewer()->create())->getJson(route('dashboard.hr.activity'))->assertForbidden();
        $this->actingAs(User::factory()->hrOfficer()->create())->getJson(route('dashboard.hr.activity'))->assertOk();
    }

    public function test_latest_daily_events_have_correct_identity_outcome_and_collection_freshness(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 08:00:00', 'UTC'));
        $person = Personnel::create(['employee_no' => '007', 'name' => 'Correct <Full> Name']);
        $terminal = Terminal::create(['name' => 'Test Entrance', 'ip' => '192.0.2.1', 'port' => 80, 'username' => 'test', 'password' => 'test', 'status' => 'online', 'last_checked_at' => now()]);
        TerminalEventSync::create(['terminal_id' => $terminal->id, 'started_at' => now()->startOfDay(), 'through_at' => now()->subSeconds(20), 'last_success_at' => now()]);
        $create = fn ($key, $time, $minor = 38) => AccessEvent::create(['event_key' => $key, 'terminal_id' => $terminal->id, 'employee_no' => '007', 'person_name' => 'Outdated Name', 'occurred_at' => $time, 'major' => 5, 'minor' => $minor, 'raw_payload' => []]);
        $create('yesterday', '2026-11-17 21:59:59');
        $create('today-midnight', '2026-11-17 22:00:00');
        $create('future', now()->addSecond());
        $create('latest-denied', now(), 39);
        $this->actingAs(User::factory()->hrOfficer()->create());
        $response = $this->getJson(route('dashboard.hr.activity'))->assertOk()
            ->assertJsonCount(2, 'events')->assertJsonPath('events.0.outcome', 'Denied')
            ->assertJsonPath('events.0.name', $person->name)->assertJsonPath('events.0.employee_id', $person->employee_id)
            ->assertJsonPath('events.0.terminal', 'Test Entrance')->assertJsonPath('events.0.direction', 'Not reported')
            ->assertJsonPath('events.1.time', '00:00:00')->assertJsonPath('terminals.0.status', 'Current');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('dashboard.hr'))->assertOk()->assertSee('Correct &lt;Full&gt; Name', false)->assertDontSee('Outdated Name');
        TerminalEventSync::where('terminal_id', $terminal->id)->update(['through_at' => now()->subHours(2)]);
        $this->getJson(route('dashboard.hr.activity'))->assertJsonPath('terminals.0.status', 'Delayed');
        $terminal->update(['status' => 'offline']);
        $this->getJson(route('dashboard.hr.activity'))->assertJsonPath('terminals.0.status', 'Offline');
    }

    public function test_feed_is_bounded_and_newest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 08:00:00', 'UTC'));
        $terminal = Terminal::create(['name' => 'Test Entrance', 'ip' => '192.0.2.1', 'port' => 80, 'username' => 'test', 'password' => 'test']);
        for ($i = 0; $i < 55; $i++) {
            AccessEvent::create(['terminal_id' => $terminal->id, 'event_key' => 'event-'.$i, 'employee_no' => 'unknown', 'person_name' => 'Device Name', 'occurred_at' => now()->subSeconds($i), 'raw_payload' => []]);
        }
        $this->actingAs(User::factory()->hrOfficer()->create())->getJson(route('dashboard.hr.activity'))->assertOk()
            ->assertJsonCount(50, 'events')->assertJsonPath('events.0.time', '10:00:00')
            ->assertJsonPath('events.0.terminal', 'Test Entrance')->assertJsonPath('events.0.outcome', 'Info');
    }
}
