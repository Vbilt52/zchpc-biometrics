<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_executive_counts_use_granted_events_and_named_controllers(): void
    {
        foreach (['Main Entrance', 'Server Room', 'UPS ROOM'] as $index => $name) {
            $terminal = Terminal::create(['name' => $name, 'ip' => '192.0.2.'.($index + 1), 'username' => 'test', 'password' => 'test']);
            AccessEvent::create(['terminal_id' => $terminal->id, 'employee_no' => (string) $index, 'occurred_at' => now(), 'event_key' => 'grant-'.$index, 'major' => 5, 'minor' => 38, 'raw_payload' => ['doorNo' => 2]]);
        }
        AccessEvent::create(['terminal_id' => $terminal->id, 'employee_no' => 'denied-only', 'occurred_at' => now(), 'event_key' => 'denied', 'major' => 5, 'minor' => 39, 'raw_payload' => []]);
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('dashboard.executive'))->assertOk()
            ->assertViewHas('uniquePresentToday', 3)->assertViewHas('serverRoomEntries', 1)->assertViewHas('upsRoomEntries', 1)->assertViewHas('failedAttemptsToday', 1);
    }

    public function test_pdf_limit_is_explicit_instead_of_silently_truncating(): void
    {
        $terminal = Terminal::create(['name' => 'Entry', 'ip' => '192.0.2.1', 'username' => 'test', 'password' => 'test']);
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['terminal_id' => $terminal->id, 'event_key' => 'event-'.$i, 'occurred_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now(), 'raw_payload' => '{}'];
        }
        AccessEvent::insert($rows);
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('events.export.pdf'))->assertStatus(422)->assertSee('500 records');
    }
}
