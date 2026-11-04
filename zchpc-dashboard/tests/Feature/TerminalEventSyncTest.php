<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Terminal;
use App\Models\TerminalEventSync;
use App\Models\User;
use App\Services\EventImportService;
use App\Services\TerminalEventSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class TerminalEventSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function terminal(): Terminal
    {
        return Terminal::create(['name' => 'Entrance', 'phase' => 'Phase 1', 'ip' => '192.0.2.1', 'username' => 'admin', 'password' => 'device-secret']);
    }

    public function test_import_pages_by_actual_count_reuses_search_id_and_deduplicates(): void
    {
        $terminal = $this->terminal();
        $client = Mockery::mock(HikvisionClient::class);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->with('Entrance')->andReturn($client);
        $searches = [];
        $positions = [];
        $client->shouldReceive('post')->times(4)->andReturnUsing(function ($url, $payload) use (&$searches, &$positions) {
            $condition = $payload['AcsEventCond'];
            $searches[] = $condition['searchID'];
            $positions[] = $condition['searchResultPosition'];
            $position = $condition['searchResultPosition'];

            return ['AcsEvent' => ['responseStatusStrg' => $position === 0 ? 'MORE' : 'OK', 'InfoList' => [[
                'employeeNoString' => '0007', 'name' => 'Prince', 'major' => 5, 'minor' => 38,
                'time' => $position === 0 ? '2026-10-28T08:00:00+02:00' : '2026-10-28T09:00:00+02:00',
            ]]]];
        });
        $importer = new EventImportService($manager);
        $from = Carbon::parse('2026-10-28T00:00:00+02:00');
        $to = $from->copy()->endOfDay();
        $this->assertSame(2, $importer->import($terminal, $from, $to, [])['imported']);
        $this->assertSame(0, $importer->import($terminal, $from, $to, [])['imported']);
        $this->assertDatabaseCount('access_events', 2);
        $this->assertSame([0, 1, 0, 1], $positions);
        $this->assertSame($searches[0], $searches[1]);
        $this->assertNotSame($searches[0], $searches[2]);
        $this->assertSame('2026-10-28 06:00:00', AccessEvent::first()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_unrecognized_device_response_cannot_be_marked_complete(): void
    {
        $terminal = $this->terminal();
        $client = Mockery::mock(HikvisionClient::class);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andReturn($client);
        $client->shouldReceive('post')->once()->andReturn(['statusString' => 'Invalid Operation']);
        $this->travelTo(Carbon::parse('2026-10-28T12:00:00Z'));
        (new TerminalEventSyncService(new EventImportService($manager)))->sync($terminal);
        $state = TerminalEventSync::firstOrFail();
        $this->assertNull($state->through_at);
        $this->assertNotNull($state->last_error);
    }

    public function test_failed_window_keeps_checkpoint_and_next_run_retries_with_overlap(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28T12:00:00Z'));
        $terminal = $this->terminal();
        $state = TerminalEventSync::create(['terminal_id' => $terminal->id, 'started_at' => '2026-10-27 22:00:00', 'through_at' => '2026-10-28 11:55:00']);
        $importer = Mockery::mock(EventImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('cURL error 7'));
        (new TerminalEventSyncService($importer))->sync($terminal);
        $this->assertSame('2026-10-28 11:55:00', $state->fresh()->through_at->toDateTimeString());
        $this->assertNotNull($state->fresh()->last_error);
        $retry = Mockery::mock(EventImportService::class);
        $retry->shouldReceive('import')->once()->withArgs(function ($device, $from, $to) {
            return $from->toDateTimeString() === '2026-10-28 11:53:00' && $to->toDateTimeString() === '2026-10-28 11:59:50';
        })->andReturn(['imported' => 1, 'updated' => 1, 'truncated' => false]);
        (new TerminalEventSyncService($retry))->sync($terminal);
        $this->assertSame('2026-10-28 11:59:50', $state->fresh()->through_at->toDateTimeString());
        $this->assertNull($state->fresh()->last_error);
    }

    public function test_truncated_window_does_not_advance_checkpoint(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28T12:00:00Z'));
        $terminal = $this->terminal();
        $importer = Mockery::mock(EventImportService::class);
        $importer->shouldReceive('import')->times(12)->andReturn(['imported' => 5000, 'updated' => 0, 'truncated' => true]);
        (new TerminalEventSyncService($importer))->sync($terminal);
        $this->assertNull(TerminalEventSync::first()->through_at);
        $this->assertNotNull(TerminalEventSync::first()->last_error);
    }

    public function test_monitoring_shows_sync_status_and_prince_example(): void
    {
        $this->terminal();
        $this->actingAs(User::factory()->create())->get('/events')
            ->assertOk()->assertSee('Waiting for first collection')->assertSee('Prince');
    }
}
