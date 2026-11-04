<?php

namespace Tests\Feature;

use App\Models\DeviceActivityLog;
use App\Models\DeviceAlert;
use App\Models\DeviceMonitorSettings;
use App\Models\DeviceRefreshItem;
use App\Models\DeviceRefreshRun;
use App\Models\Terminal;
use App\Models\User;
use App\Services\DeviceAlertService;
use App\Services\DeviceRefreshService;
use App\Services\NetworkReachability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Exceptions\AuthenticationException;
use Shaykhnazar\HikvisionIsapi\Exceptions\DeviceBusyException;
use Tests\TestCase;

class DeviceMonitoringTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function terminal(string $name, array $attributes = []): Terminal
    {
        return Terminal::create(array_merge([
            'name' => $name,
            'phase' => 'Phase 1',
            'location' => 'Main Building',
            'ip' => '192.0.2.'.random_int(10, 200),
            'port' => 80,
            'username' => 'admin',
            'password' => 'device-secret',
            'protocol' => 'http',
            'timeout' => 1,
            'integration' => Terminal::INTEGRATION_HIKVISION_ISAPI,
            'model' => 'DS-K1T341',
        ], $attributes));
    }

    /**
     * @param  array<string, array{reachable: bool, latency_ms?: int|null, error?: string|null}>  $overrides  Keyed by "host:port".
     */
    private function fakeReachability(bool $defaultReachable = true, array $overrides = []): void
    {
        $probe = function (string $host, int $port) use ($defaultReachable, $overrides): array {
            $key = $host.':'.$port;

            if (array_key_exists($key, $overrides)) {
                return $overrides[$key] + ['latency_ms' => 4, 'error' => null];
            }

            return $defaultReachable
                ? ['reachable' => true, 'latency_ms' => 11, 'error' => null]
                : ['reachable' => false, 'latency_ms' => 3, 'error' => 'Connection refused.'];
        };

        $mock = Mockery::mock(NetworkReachability::class);
        $mock->shouldReceive('probe')->andReturnUsing(
            fn (string $host, int $port, ?float $timeout = null): array => $probe($host, $port)
        );
        $mock->shouldReceive('probeMany')->andReturnUsing(function (array $targets, int $concurrency = 4, ?float $timeout = null) use ($probe): array {
            $results = [];

            foreach ($targets as $key => $target) {
                $results[$key] = $probe($target['host'], $target['port']);
            }

            return $results;
        });

        $this->app->instance(NetworkReachability::class, $mock);
    }

    /**
     * @param  array<string, callable(): array|\Throwable>  $responders  Keyed by terminal name.
     */
    private function fakeDevices(array $responders): void
    {
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('reload')->andReturnNull();

        foreach ($responders as $name => $responder) {
            $client = Mockery::mock(HikvisionClient::class);
            $client->shouldReceive('get')->with('/ISAPI/System/deviceInfo')->andReturnUsing(function () use ($responder) {
                $result = $responder();

                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });
            $client->shouldReceive('get')->with('/ISAPI/System/time')
                ->andReturn(['localTime' => '2026-10-29T23:30:00+02:00']);
            $manager->shouldReceive('device')->with($name)->andReturn($client);
        }

        $this->app->instance(DeviceManager::class, $manager);
    }

    private function deviceInfo(): array
    {
        return [
            'deviceName' => 'DS-K1T341',
            'model' => 'DS-K1T341',
            'serialNumber' => 'DS-0001',
            'firmwareVersion' => 'V3.3.0',
        ];
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function test_dashboard_reports_live_statistics_and_separates_unmonitored_readers(): void
    {
        $online = $this->terminal('Main Entrance', ['status' => Terminal::STATUS_ONLINE, 'last_checked_at' => now(), 'last_seen_at' => now()]);
        $offline = $this->terminal('Server Room', ['status' => Terminal::STATUS_OFFLINE, 'last_checked_at' => now(), 'consecutive_failures' => 4, 'last_error' => 'No connection.']);
        $error = $this->terminal('UPS Room', ['status' => Terminal::STATUS_ERROR, 'last_checked_at' => now(), 'last_error' => 'Credentials rejected.']);
        $standalone = $this->terminal('Exit Button Reader', [
            'integration' => Terminal::INTEGRATION_STANDALONE_WIEGAND,
            'model' => 'CTD reader',
        ]);

        $response = $this->actingAs($this->admin())->get(route('devices.index'));

        $response->assertOk()
            ->assertSee('Device Management')
            ->assertSee('Main Entrance')
            ->assertSee('Server Room')
            ->assertSee('Exit Button Reader')
            ->assertSee('No remote API')
            ->assertSee('Refresh all')
            ->assertSee('Refresh offline');

        $response->assertViewHas('stats', fn (array $stats): bool => $stats === [
            'total' => 4,
            'online' => 1,
            'offline' => 1,
            'connecting' => 0,
            'error' => 1,
            'attention' => 2,
            'unmonitored' => 1,
        ]);

        $this->actingAs($this->admin())
            ->get(route('devices.index', ['status' => 'online']))
            ->assertOk()
            ->assertSee('Main Entrance')
            ->assertDontSee('Server Room');
    }

    public function test_live_status_endpoint_returns_timestamps_and_labels(): void
    {
        $terminal = $this->terminal('Main Entrance', [
            'status' => Terminal::STATUS_ONLINE,
            'last_checked_at' => now(),
            'last_seen_at' => now(),
            'last_latency_ms' => 42,
        ]);

        $response = $this->actingAs($this->admin())->getJson(route('devices.statuses'));

        $response->assertOk()
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.online', 1)
            ->assertJsonPath('devices.0.id', $terminal->id)
            ->assertJsonPath('devices.0.status_label', 'Online')
            ->assertJsonPath('devices.0.latency_ms', 42)
            ->assertJsonPath('devices.0.monitored', true)
            ->assertJsonPath('settings.interval_seconds', 60);

        $this->assertNotSame('Never', $response->json('devices.0.last_checked'));
    }

    // ------------------------------------------------------------------
    // Individual refresh
    // ------------------------------------------------------------------

    public function test_refreshing_one_device_updates_only_that_device_and_records_the_outcome(): void
    {
        $target = $this->terminal('Main Entrance', [
            'status' => Terminal::STATUS_OFFLINE,
            'last_checked_at' => now()->subMinutes(5),
            'consecutive_failures' => 2,
            'last_error' => 'Previous failure',
        ]);
        $other = $this->terminal('Server Room', ['status' => Terminal::STATUS_OFFLINE]);

        $this->fakeReachability();
        $this->fakeDevices(['Main Entrance' => fn () => $this->deviceInfo()]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('devices.refresh', $target));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('device.status', 'online')
            ->assertJsonPath('device.status_label', 'Online')
            ->assertJsonPath('stats.online', 1);

        $target->refresh();
        $this->assertSame(Terminal::STATUS_ONLINE, $target->status);
        $this->assertSame('refresh', $target->status_source);
        $this->assertNotNull($target->last_checked_at);
        $this->assertNotNull($target->last_seen_at);
        $this->assertNull($target->last_error);
        $this->assertSame(0, $target->consecutive_failures);
        $this->assertGreaterThan(0, $target->last_latency_ms);

        // The other device was not contacted or modified.
        $other->refresh();
        $this->assertSame(Terminal::STATUS_OFFLINE, $other->status);
        $this->assertNull($other->last_checked_at);

        $this->assertDatabaseHas('device_activity_logs', [
            'terminal_id' => $target->id,
            'action' => 'refresh',
            'outcome' => 'success',
        ]);
        $this->assertDatabaseHas('device_alerts', [
            'terminal_id' => $target->id,
            'type' => 'device_online',
            'severity' => 'info',
        ]);
        // Cached info is refreshed for the devices page.
        $this->assertIsArray(cache('terminal.'.$target->id.'.info'));
    }

    public function test_refreshing_an_unreachable_device_reports_a_diagnostic_and_stays_offline(): void
    {
        $terminal = $this->terminal('Server Room', [
            'status' => Terminal::STATUS_ONLINE,
            'last_seen_at' => now()->subMinutes(30),
            'last_checked_at' => now()->subMinutes(1),
        ]);

        $this->fakeReachability(false);
        // No device client is expected: an unreachable port must not be asked
        // for an authenticated read.
        $this->fakeDevices([]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('devices.refresh', $terminal));

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('device.status', 'offline');

        $this->assertStringContainsString('No connection to '.$terminal->ip, $response->json('message'));

        $terminal->refresh();
        $this->assertSame(Terminal::STATUS_OFFLINE, $terminal->status);
        $this->assertStringContainsString('No connection', (string) $terminal->last_error);
        $this->assertSame(1, $terminal->consecutive_failures);
        // A failed check must never move the last successful communication.
        $this->assertTrue($terminal->last_seen_at->lt(now()->subMinutes(5)));

        $this->assertDatabaseHas('device_alerts', [
            'terminal_id' => $terminal->id,
            'type' => 'device_offline',
            'severity' => 'critical',
        ]);
    }

    public function test_authentication_failure_is_recorded_as_an_error_not_online(): void
    {
        $terminal = $this->terminal('UPS Room');

        $this->fakeReachability();
        $this->fakeDevices([
            'UPS Room' => fn () => new AuthenticationException('HTTP 401'),
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('devices.refresh', $terminal));

        $response->assertOk()->assertJsonPath('ok', false)->assertJsonPath('device.status', 'error');
        $this->assertStringContainsString('rejected its saved credentials', $response->json('message'));

        $terminal->refresh();
        $this->assertSame(Terminal::STATUS_ERROR, $terminal->status);
        $this->assertNull($terminal->last_seen_at);
        $this->assertSame(1, $terminal->consecutive_failures);
        $this->assertStringContainsString('credentials', (string) $terminal->last_error);

        $this->assertDatabaseHas('device_alerts', [
            'terminal_id' => $terminal->id,
            'type' => 'authentication_failure',
        ]);
    }

    public function test_a_busy_device_response_is_an_error_with_a_retry_hint(): void
    {
        $terminal = $this->terminal('Main Entrance');

        $this->fakeReachability();
        $this->fakeDevices([
            'Main Entrance' => fn () => new DeviceBusyException('HTTP 503', 0, null, 503, 'busy'),
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh', $terminal))
            ->assertOk()
            ->assertJsonPath('device.status', 'error');

        $terminal->refresh();
        $this->assertSame(Terminal::STATUS_ERROR, $terminal->status);
        $this->assertStringContainsString('busy', (string) $terminal->last_error);
    }

    public function test_standalone_readers_cannot_be_refreshed_remotely(): void
    {
        $reader = $this->terminal('Exit Reader', [
            'integration' => Terminal::INTEGRATION_STANDALONE_WIEGAND,
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh', $reader))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    // ------------------------------------------------------------------
    // Bulk refresh
    // ------------------------------------------------------------------

    public function test_refresh_all_checks_every_managed_device_and_summarises_the_run(): void
    {
        $online = $this->terminal('Main Entrance');
        $offline = $this->terminal('Server Room');
        $this->terminal('Exit Reader', ['integration' => Terminal::INTEGRATION_STANDALONE_WIEGAND]);

        $this->fakeReachability(true, [
            $offline->ip.':'.$offline->port => ['reachable' => false, 'error' => 'Connection refused.'],
        ]);
        $this->fakeDevices(['Main Entrance' => fn () => $this->deviceInfo()]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('devices.refresh.bulk'), ['scope' => 'all']);

        $response->assertStatus(202)->assertJsonPath('started', true);

        $run = DeviceRefreshRun::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $run->total);
        $this->assertSame(DeviceRefreshRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->completed);
        $this->assertSame(1, $run->failed);
        $this->assertStringContainsString('1 online', (string) $run->message);
        $this->assertStringContainsString('1 offline', (string) $run->message);

        $this->assertSame(
            [DeviceRefreshItem::STATUS_ONLINE, DeviceRefreshItem::STATUS_OFFLINE],
            $run->items()->orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame(Terminal::STATUS_ONLINE, $online->refresh()->status);
        $this->assertSame(Terminal::STATUS_OFFLINE, $offline->refresh()->status);

        $this->assertDatabaseHas('device_activity_logs', ['action' => 'refresh_all', 'outcome' => 'info']);
        $this->assertDatabaseHas('device_activity_logs', ['action' => 'refresh_completed', 'outcome' => 'info']);
    }

    public function test_refresh_offline_only_rechecks_devices_marked_offline(): void
    {
        $this->terminal('Online Entrance', ['status' => Terminal::STATUS_ONLINE, 'last_checked_at' => now()]);
        $offline = $this->terminal('Server Room', ['status' => Terminal::STATUS_OFFLINE, 'last_checked_at' => now()]);
        $this->terminal('Never Checked');

        $this->fakeReachability();
        $this->fakeDevices(['Server Room' => fn () => $this->deviceInfo()]);

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh.bulk'), ['scope' => 'offline'])
            ->assertStatus(202);

        $run = DeviceRefreshRun::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $run->total);
        $this->assertSame('Server Room', $run->items()->sole()->terminal_name);
        $this->assertSame(Terminal::STATUS_ONLINE, $offline->refresh()->status);
    }

    public function test_refresh_selected_checks_only_the_selected_devices(): void
    {
        $selected = $this->terminal('Main Entrance');
        $this->terminal('Back Entrance');

        $this->fakeReachability();
        $this->fakeDevices(['Main Entrance' => fn () => $this->deviceInfo()]);

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh.bulk'), ['scope' => 'selected', 'ids' => [$selected->id]])
            ->assertStatus(202);

        $run = DeviceRefreshRun::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $run->total);
        $this->assertSame('Main Entrance', $run->items()->sole()->terminal_name);
        $this->assertNull(Terminal::where('name', 'Back Entrance')->value('last_checked_at'));
    }

    public function test_selected_refresh_requires_at_least_one_device(): void
    {
        $this->terminal('Main Entrance');

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh.bulk'), ['scope' => 'selected', 'ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_a_second_refresh_cannot_start_while_one_is_running(): void
    {
        $this->terminal('Main Entrance');

        $active = DeviceRefreshRun::create([
            'scope' => 'all',
            'status' => DeviceRefreshRun::STATUS_RUNNING,
            'total' => 1,
            'started_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh.bulk'), ['scope' => 'all'])
            ->assertStatus(409)
            ->assertJsonPath('started', false)
            ->assertJsonPath('run.id', $active->id);

        $this->assertSame(1, DeviceRefreshRun::count());
    }

    public function test_cancelling_a_run_stops_pending_checks_and_is_audited(): void
    {
        $terminal = $this->terminal('Main Entrance');
        $run = DeviceRefreshRun::create([
            'scope' => 'all',
            'status' => DeviceRefreshRun::STATUS_RUNNING,
            'total' => 2,
            'started_at' => now(),
        ]);
        $run->items()->create(['terminal_id' => $terminal->id, 'terminal_name' => 'Main Entrance', 'status' => 'pending']);
        $run->items()->create(['terminal_id' => null, 'terminal_name' => 'Removed device', 'status' => 'pending']);

        $this->fakeReachability();
        $this->fakeDevices([]);

        $this->actingAs($this->admin())
            ->postJson(route('devices.refresh.cancel', $run))
            ->assertOk()
            ->assertJsonPath('run.status', 'cancelled');

        $this->assertSame(2, $run->refresh()->cancelled);
        $this->assertSame([DeviceRefreshItem::STATUS_CANCELLED], $run->items()->distinct()->pluck('status')->all());
        $this->assertNull($terminal->refresh()->last_checked_at);
        $this->assertDatabaseHas('device_activity_logs', ['action' => 'refresh_cancelled']);

        // A worker that picks the job up afterwards must not contact a device.
        app(DeviceRefreshService::class)->process($run->refresh());
        $this->assertNull($terminal->refresh()->last_checked_at);
    }

    // ------------------------------------------------------------------
    // Diagnostics and reconnect
    // ------------------------------------------------------------------

    public function test_diagnostics_report_each_layer_without_exposing_secrets(): void
    {
        $terminal = $this->terminal('Main Entrance', [
            'status' => Terminal::STATUS_ONLINE,
            'last_checked_at' => now(),
            'last_seen_at' => now(),
            'last_latency_ms' => 55,
            'consecutive_failures' => 0,
        ]);

        $this->fakeReachability();
        $this->fakeDevices(['Main Entrance' => fn () => $this->deviceInfo()]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('devices.diagnostics', $terminal));

        $response->assertOk()
            ->assertJsonPath('network.tested', true)
            ->assertJsonPath('network.reachable', true)
            ->assertJsonPath('protocol.tested', true)
            ->assertJsonPath('protocol.ok', true)
            ->assertJsonPath('authentication.status', 'accepted')
            ->assertJsonPath('history.last_response_ms', 55)
            ->assertJsonPath('gateway.supported', false)
            ->assertJsonPath('device_info.serialNumber', 'DS-0001')
            ->assertJsonPath('device_clock.reported_local_time', '2026-10-29T23:30:00+02:00');

        $this->assertIsArray($response->json('recommendations'));
        $this->assertNotSame([], $response->json('recommendations'));

        $raw = $response->getContent();
        $this->assertStringNotContainsString('device-secret', $raw);
        $this->assertStringNotContainsString('192.0.2.', (string) $response->json('device_info.serialNumber'));

        $this->assertDatabaseHas('device_activity_logs', [
            'terminal_id' => $terminal->id,
            'action' => 'diagnostics',
            'outcome' => 'success',
        ]);
        // Diagnostics must not change connectivity state.
        $this->assertSame(Terminal::STATUS_ONLINE, $terminal->refresh()->status);
    }

    public function test_diagnostics_do_not_modify_an_offline_device_status(): void
    {
        $terminal = $this->terminal('Server Room', [
            'status' => Terminal::STATUS_OFFLINE,
            'last_checked_at' => now()->subHour(),
            'last_error' => 'No connection.',
            'consecutive_failures' => 5,
        ]);

        $this->fakeReachability(false);
        $this->fakeDevices([]);

        $this->actingAs($this->admin())
            ->getJson(route('devices.diagnostics', $terminal))
            ->assertOk()
            ->assertJsonPath('network.reachable', false)
            ->assertJsonPath('protocol.tested', false)
            ->assertJsonPath('authentication.status', 'not-verified');

        $terminal->refresh();
        $this->assertSame(Terminal::STATUS_OFFLINE, $terminal->status);
        $this->assertSame(5, $terminal->consecutive_failures);
    }

    public function test_reconnect_clears_the_cached_connection_and_is_separate_from_refresh(): void
    {
        $terminal = $this->terminal('Main Entrance');

        $this->fakeReachability();
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('reload')->once();
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('get')->with('/ISAPI/System/deviceInfo')->andReturn($this->deviceInfo());
        $manager->shouldReceive('device')->with('Main Entrance')->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);

        $this->actingAs($this->admin())
            ->postJson(route('devices.reconnect', $terminal))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $terminal->refresh();
        $this->assertSame(Terminal::STATUS_ONLINE, $terminal->status);
        $this->assertNotNull($terminal->last_reconnect_at);
        $this->assertDatabaseHas('device_activity_logs', ['terminal_id' => $terminal->id, 'action' => 'reconnect', 'outcome' => 'success']);

        // Remote restart is deliberately not offered.
        $this->assertFalse(Route::has('devices.restart'));
    }

    // ------------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------------

    public function test_repeated_alerts_inside_the_cooldown_are_counted_on_one_alert(): void
    {
        $terminal = $this->terminal('Server Room');

        $this->fakeReachability(false);
        $this->fakeDevices([]);

        $this->actingAs($this->admin())->postJson(route('devices.refresh', $terminal))->assertOk();
        $this->actingAs($this->admin())->postJson(route('devices.refresh', $terminal))->assertOk();

        $alerts = DeviceAlert::query()->where('terminal_id', $terminal->id)->where('type', 'device_offline')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame(2, $alerts->first()->occurrences);
        $this->assertNull($alerts->first()->acknowledged_at);
    }

    public function test_acknowledging_alerts_requires_an_operator_and_records_who_did_it(): void
    {
        $terminal = $this->terminal('Server Room');
        $alert = DeviceAlert::create([
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'type' => 'device_offline',
            'severity' => 'critical',
            'title' => 'Device offline',
            'message' => 'No connection.',
            'occurrences' => 1,
            'first_occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);

        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer)
            ->post(route('devices.alerts.acknowledge', $alert))
            ->assertForbidden();

        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $this->actingAs($operator)
            ->get(route('devices.alerts.index'))
            ->assertOk()
            ->assertSee('Device offline');

        $this->actingAs($operator)
            ->post(route('devices.alerts.acknowledge', $alert))
            ->assertRedirect();

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame($operator->name, $alert->acknowledged_by_name);

        $this->actingAs($operator)
            ->post(route('devices.alerts.acknowledge-all'))
            ->assertRedirect();

        $this->assertDatabaseHas('device_activity_logs', ['action' => 'alert_acknowledged']);
    }

    public function test_synchronization_failures_raise_a_deduplicated_alert(): void
    {
        $terminal = $this->terminal('Main Entrance');
        $alerts = app(DeviceAlertService::class);

        $alerts->syncFailed($terminal, 'Collection could not complete.');
        $alerts->syncFailed($terminal, 'Collection could not complete.');

        $this->assertSame(1, DeviceAlert::query()->where('type', 'sync_failure')->count());
        $this->assertSame(2, DeviceAlert::query()->where('type', 'sync_failure')->firstOrFail()->occurrences);
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_operators_can_view_and_diagnose_but_not_refresh_or_change_settings(): void
    {
        $terminal = $this->terminal('Main Entrance');
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);

        $this->fakeReachability();
        $this->fakeDevices(['Main Entrance' => fn () => $this->deviceInfo()]);

        $this->actingAs($operator)
            ->postJson(route('devices.refresh', $terminal))
            ->assertForbidden();
        $this->assertNull($terminal->refresh()->last_checked_at);

        $this->actingAs($operator)
            ->postJson(route('devices.refresh.bulk'), ['scope' => 'all'])
            ->assertForbidden();

        $this->actingAs($operator)
            ->post(route('devices.monitor.settings'), ['interval_seconds' => 30, 'alert_cooldown_minutes' => 5])
            ->assertForbidden();

        $this->actingAs($operator)
            ->getJson(route('devices.diagnostics', $terminal))
            ->assertOk();

        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer)
            ->getJson(route('devices.diagnostics', $terminal))
            ->assertForbidden();
    }

    public function test_monitoring_settings_can_be_changed_by_an_administrator(): void
    {
        $terminal = $this->terminal('Main Entrance', ['status' => Terminal::STATUS_ONLINE]);

        $this->actingAs($this->admin())
            ->post(route('devices.monitor.settings'), [
                'enabled' => '1',
                'interval_seconds' => 300,
                'alert_cooldown_minutes' => 20,
            ])
            ->assertRedirect();

        $settings = DeviceMonitorSettings::current()->refresh();
        $this->assertSame(300, $settings->interval_seconds);
        $this->assertSame(20, $settings->alert_cooldown_minutes);
        $this->assertTrue($settings->enabled);
        $this->assertDatabaseHas('device_activity_logs', ['action' => 'monitor_settings']);
    }

    // ------------------------------------------------------------------
    // Scheduled monitoring
    // ------------------------------------------------------------------

    public function test_scheduled_monitoring_respects_the_configured_interval_and_the_pause_switch(): void
    {
        DeviceMonitorSettings::current()->update(['enabled' => true, 'interval_seconds' => 60]);
        $terminal = $this->terminal('Main Entrance', [
            'status' => Terminal::STATUS_ONLINE,
            'last_checked_at' => now()->subSeconds(10),
        ]);

        $this->fakeReachability();
        $this->fakeDevices(['Main Entrance' => fn () => $this->deviceInfo()]);

        // Checked 10 seconds ago: the interval has not elapsed.
        $this->artisan('terminals:ping')->assertSuccessful();
        $this->assertTrue($terminal->refresh()->last_checked_at->lt(now()->subSeconds(5)));

        // Paused monitoring performs no device work, even when due.
        DeviceMonitorSettings::current()->update(['enabled' => false]);
        $terminal->forceFill(['last_checked_at' => null])->save();
        $this->artisan('terminals:ping')->assertSuccessful();
        $this->assertNull($terminal->refresh()->last_checked_at);

        // A forced run checks immediately and stores the result.
        $this->artisan('terminals:ping', ['--force' => true])->assertSuccessful();
        $this->assertSame(Terminal::STATUS_ONLINE, $terminal->refresh()->status);
        $this->assertNotNull($terminal->last_checked_at);
    }

    public function test_a_due_scheduled_check_retries_transient_failures_and_records_offline(): void
    {
        DeviceMonitorSettings::current()->update(['enabled' => true, 'interval_seconds' => 60]);
        $terminal = $this->terminal('Server Room');
        $this->fakeReachability(false);
        $this->fakeDevices([]);

        $this->artisan('terminals:ping')->assertSuccessful();

        $terminal->refresh();
        $this->assertSame(Terminal::STATUS_OFFLINE, $terminal->status);
        $this->assertSame('scheduled', $terminal->status_source);
        $this->assertNotNull($terminal->last_checked_at);
        $this->assertNotNull($terminal->last_error);
        $this->assertDatabaseHas('device_activity_logs', ['terminal_id' => $terminal->id, 'action' => 'status_change', 'outcome' => 'failure']);
    }

    // ------------------------------------------------------------------
    // Activity log
    // ------------------------------------------------------------------

    public function test_activity_log_is_filterable_and_exportable(): void
    {
        $terminal = $this->terminal('Main Entrance');
        DeviceActivityLog::create([
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'user_id' => null,
            'user_name' => 'System',
            'action' => 'refresh',
            'outcome' => 'failure',
            'message' => 'No connection.',
            'created_at' => now(),
        ]);
        DeviceActivityLog::create([
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'user_id' => null,
            'user_name' => 'System',
            'action' => 'refresh_completed',
            'outcome' => 'success',
            'message' => 'Checked 1 of 1 device(s).',
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('devices.activity.index', ['outcome' => 'failure']))
            ->assertOk()
            ->assertSee('No connection.')
            ->assertDontSee('Checked 1 of 1 device(s).');

        $export = $this->actingAs($this->admin())
            ->get(route('devices.activity.export', ['outcome' => 'failure']));
        $export->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));

        $csv = $export->streamedContent();
        $this->assertStringContainsString('Main Entrance', $csv);
        $this->assertStringContainsString('No connection.', $csv);
        $this->assertStringNotContainsString('Checked 1 of 1 device(s).', $csv);
    }

    public function test_activity_export_neutralises_spreadsheet_formulas(): void
    {
        $terminal = $this->terminal('Main Entrance');
        DeviceActivityLog::create([
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'user_name' => 'System',
            'action' => 'refresh',
            'outcome' => 'failure',
            'message' => '=cmd|calc',
            'created_at' => now(),
        ]);

        $csv = $this->actingAs($this->admin())
            ->get(route('devices.activity.export'))
            ->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }
}
