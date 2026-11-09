<?php

namespace Tests\Feature;

use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\TerminalSuperFingerprint;
use App\Models\User;
use App\Services\PendingTerminalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class EventLogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_selected_terminal_logs_can_be_imported_searched_and_exported(): void
    {
        $user = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();
        $timestamp = now()->subMinute()->format(DATE_ATOM);

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with('/ISAPI/AccessControl/AcsEvent', Mockery::type('array'))
            ->andReturn([
                'AcsEvent' => [
                    'InfoList' => [[
                        'time' => $timestamp,
                        'employeeNoString' => $person->employee_no,
                        'major' => 5,
                        'minor' => 38,
                        'verifyMode' => 'fingerprint',
                        'eventType' => 'AccessGranted',
                    ]],
                    'responseStatusStrg' => 'OK',
                ],
            ]);

        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->once()->with($terminal->name)->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);

        $range = [
            'import_terminal_id' => $terminal->id,
            'import_from' => now(config('attendance.timezone'))->subHour()->format('Y-m-d\TH:i'),
            'import_to' => now(config('attendance.timezone'))->format('Y-m-d\TH:i'),
        ];

        $this->actingAs($user)
            ->post(route('events.import'), $range)
            ->assertSessionHas('success');

        $this->assertDatabaseHas('access_events', [
            'terminal_id' => $terminal->id,
            'employee_no' => $person->employee_no,
            'person_name' => $person->name,
        ]);

        $filters = [
            'terminal_id' => $terminal->id,
            'personnel_id' => $person->id,
            'from' => now(config('attendance.timezone'))->subHour()->format('Y-m-d\TH:i'),
            'to' => now(config('attendance.timezone'))->format('Y-m-d\TH:i'),
        ];
        $this->actingAs($user)
            ->get(route('events.index', $filters))
            ->assertOk()
            ->assertSee($person->name)
            ->assertSee($person->employee_no)
            ->assertSee('Fingerprint');

        $pdf = $this->actingAs($user)->get(route('events.export.pdf', $filters));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $word = $this->actingAs($user)->get(route('events.export.word', $filters));
        $word->assertOk()->assertHeader('content-type', 'application/msword; charset=utf-8')->assertSee($person->name);
    }

    public function test_all_terminal_import_keeps_each_devices_events_separate(): void
    {
        $user = User::factory()->create();
        $mainEntrance = $this->terminal();
        $serverRoom = Terminal::create([
            'name' => 'Server Room', 'phase' => 'Phase 1', 'ip' => '192.168.60.45', 'port' => 80,
            'username' => 'admin', 'password' => 'secret', 'protocol' => 'http', 'timeout' => 5, 'verify_ssl' => false,
        ]);
        $timestamp = now()->subMinute()->format(DATE_ATOM);

        $mainClient = $this->eventClient($timestamp, 'MAIN-001');
        $serverClient = $this->eventClient($timestamp, 'SERVER-001');
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->once()->with($mainEntrance->name)->andReturn($mainClient);
        $manager->shouldReceive('device')->once()->with($serverRoom->name)->andReturn($serverClient);
        $this->app->instance(DeviceManager::class, $manager);

        $range = ['import_from' => now(config('attendance.timezone'))->subHour()->format('Y-m-d\\TH:i'), 'import_to' => now(config('attendance.timezone'))->format('Y-m-d\\TH:i')];
        $this->actingAs($user)->post(route('events.import.all'), $range)->assertSessionHas('success');

        $this->assertDatabaseHas('access_events', ['terminal_id' => $mainEntrance->id, 'employee_no' => 'MAIN-001']);
        $this->assertDatabaseHas('access_events', ['terminal_id' => $serverRoom->id, 'employee_no' => 'SERVER-001']);
        $this->actingAs($user)->get(route('events.index', $range + ['terminal_id' => $serverRoom->id]))
            ->assertOk()->assertSee('SERVER-001')->assertDontSee('MAIN-001');
    }

    public function test_super_fingerprint_enrollment_promotes_a_terminal_template_to_superfp(): void
    {
        $user = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();
        $person->terminals()->attach($terminal->id);
        $sync = Mockery::mock(PendingTerminalSyncService::class);
        $sync->shouldReceive('upsert')->once();
        $this->app->instance(PendingTerminalSyncService::class, $sync);

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')
            ->once()
            ->ordered()
            ->with('/ISAPI/AccessControl/FingerPrintUpload', Mockery::type('array'))
            ->andReturn([
                'FingerPrintInfo' => [
                    'status' => 'OK',
                    'FingerPrintList' => [[
                        'cardReaderNo' => 1,
                        'fingerPrintID' => 2,
                        'fingerType' => 'normalFP',
                        'fingerData' => 'terminal-only-template',
                    ]],
                ],
            ]);
        $client->shouldReceive('post')
            ->once()
            ->ordered()
            ->with('/ISAPI/AccessControl/FingerPrint/SetUp', Mockery::on(function (array $payload) use ($person): bool {
                return $payload['FingerPrintCfg']['employeeNo'] === $person->employee_no
                    && $payload['FingerPrintCfg']['fingerType'] === 'superFP'
                    && $payload['FingerPrintCfg']['fingerPrintID'] === 2
                    && $payload['FingerPrintCfg']['fingerData'] === 'terminal-only-template';
            }))
            ->andReturn(['FingerPrintStatus' => ['StatusList' => [['cardReaderRecvStatus' => '1']]]]);

        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->once()->with($terminal->name)->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);

        $this->actingAs($user)
            ->post(route('super-fingerprints.enroll'), [
                'terminal_id' => $terminal->id,
                'personnel_id' => $person->id,
                'finger_id' => 2,
            ])
            ->assertRedirect(route('super-fingerprints.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('terminal_super_fingerprints', [
            'terminal_id' => $terminal->id,
            'personnel_id' => $person->id,
            'finger_id' => 2,
            'status' => 'active',
        ]);
        $this->assertSame(1, TerminalSuperFingerprint::count());
    }

    public function test_super_fingerprint_picker_lists_only_the_persons_actual_terminal_fingerprints(): void
    {
        $user = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with('/ISAPI/AccessControl/FingerPrintUpload', Mockery::type('array'))
            ->andReturn([
                'FingerPrintInfo' => [
                    'FingerPrintList' => [[
                        'cardReaderNo' => 1,
                        'fingerPrintID' => 3,
                        'fingerType' => 'normalFP',
                        'fingerData' => 'must-never-leave-terminal',
                    ]],
                ],
            ]);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->once()->with($terminal->name)->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);

        $response = $this->actingAs($user)->getJson(route('super-fingerprints.records', [
            'terminal_id' => $terminal->id,
            'personnel_id' => $person->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('fingerprints.0.id', 3)
            ->assertJsonPath('fingerprints.0.type', 'normalFP')
            ->assertJsonMissing(['fingerData' => 'must-never-leave-terminal']);
    }

    public function test_access_events_can_be_filtered_by_door_and_searched_per_user(): void
    {
        $user = User::factory()->create();
        $terminal = $this->terminal();
        $door1 = \App\Models\Door::create([
            'name' => 'Server Room Door',
            'code' => 'DOOR-001',
            'terminal_id' => $terminal->id,
            'door_no' => 1,
            'is_active' => true,
        ]);
        $door2 = \App\Models\Door::create([
            'name' => 'Archive Door',
            'code' => 'DOOR-002',
            'terminal_id' => $terminal->id,
            'door_no' => 2,
            'is_active' => true,
        ]);

        $tz = config('attendance.timezone');
        $now = now($tz);

        \App\Models\AccessEvent::create([
            'terminal_id' => $terminal->id,
            'event_key' => 'event-door-1',
            'employee_no' => 'EMP-001',
            'person_name' => 'Alice Wonder',
            'occurred_at' => $now->copy()->subMinutes(10)->utc(),
            'major' => 5,
            'minor' => 38,
            'raw_payload' => ['doorNo' => 1],
        ]);

        \App\Models\AccessEvent::create([
            'terminal_id' => $terminal->id,
            'event_key' => 'event-door-2',
            'employee_no' => 'EMP-002',
            'person_name' => 'Bob Marley',
            'occurred_at' => $now->copy()->subMinutes(5)->utc(),
            'major' => 5,
            'minor' => 38,
            'raw_payload' => ['doorNo' => 2],
        ]);

        // Filter by door 1
        $response = $this->actingAs($user)->get(route('events.index', [
            'door_id' => $door1->id,
            'from' => $now->copy()->subHour()->format('Y-m-d\TH:i'),
            'to' => $now->copy()->addHour()->format('Y-m-d\TH:i'),
        ]));
        $response->assertOk()
            ->assertSee('Alice Wonder')
            ->assertDontSee('Bob Marley')
            ->assertSee('Server Room Door');

        // Search per user
        $searchResponse = $this->actingAs($user)->get(route('events.index', [
            'search' => 'Bob',
            'from' => $now->copy()->subHour()->format('Y-m-d\TH:i'),
            'to' => $now->copy()->addHour()->format('Y-m-d\TH:i'),
        ]));
        $searchResponse->assertOk()
            ->assertSee('Bob Marley')
            ->assertDontSee('Alice Wonder');
    }

    private function terminal(): Terminal
    {
        return Terminal::create([
            'name' => 'Audit Terminal',
            'phase' => 'Phase 1',
            'ip' => '192.168.60.40',
            'port' => 80,
            'username' => 'admin',
            'password' => 'secret',
            'protocol' => 'http',
            'timeout' => 5,
            'verify_ssl' => false,
        ]);
    }

    private function person(): Personnel
    {
        return Personnel::create([
            'employee_no' => 'EMP-001',
            'name' => 'Prince',
            'user_type' => 'normal',
            'valid_enabled' => true,
            'begin_time' => now()->subDay(),
            'end_time' => now()->addYear(),
            'door_right' => '1',
            'right_plan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
        ]);
    }

    private function eventClient(string $timestamp, string $employeeNo): HikvisionClient
    {
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->once()->with('/ISAPI/AccessControl/AcsEvent', Mockery::type('array'))->andReturn([
            'AcsEvent' => [
                'InfoList' => [[
                    'time' => $timestamp, 'employeeNoString' => $employeeNo, 'major' => 5, 'minor' => 38,
                    'verifyMode' => 'fingerprint', 'eventType' => 'AccessGranted',
                ]],
                'responseStatusStrg' => 'OK',
            ],
        ]);

        return $client;
    }
}
