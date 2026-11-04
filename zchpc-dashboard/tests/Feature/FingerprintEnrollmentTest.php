<?php

namespace Tests\Feature;

use App\Jobs\EnrollFingerprintJob;
use App\Models\AccessGroup;
use App\Models\FingerprintEnrollment;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\DeviceClientFactory;
use App\Services\FingerprintEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class FingerprintEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private mixed $deviceRecord = null;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function terminal(string $status = 'online'): Terminal
    {
        return Terminal::create([
            'name' => 'Main Entrance', 'phase' => 'Phase 1', 'ip' => '192.0.2.60', 'port' => 80,
            'username' => 'admin', 'password' => 'test', 'protocol' => 'http', 'timeout' => 5,
            'verify_ssl' => false, 'status' => $status, 'last_checked_at' => $status === 'online' ? now() : null,
        ]);
    }

    private function person(): Personnel
    {
        return Personnel::create([
            'employee_no' => 'EMP-700', 'name' => 'Rudo Chikore', 'user_type' => 'normal',
            'valid_enabled' => true, 'begin_time' => now()->subDay(), 'end_time' => now()->addYear(),
            'access_group_id' => AccessGroup::first()->id,
        ]);
    }

    /** Device manager used by the pre-enrollment identity provisioner. */
    private function provisioner(Terminal $terminal): void
    {
        $client = Mockery::mock(HikvisionClient::class);
        $manager = Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->with($terminal->name)->andReturn($client);

        $client->shouldReceive('post')->with('/ISAPI/AccessControl/UserInfo/Search', Mockery::type('array'))
            ->andReturnUsing(function (): array {
                return ['UserInfoSearch' => [
                    'responseStatusStrg' => $this->deviceRecord ? 'OK' : 'NO MATCH',
                    'totalMatches' => $this->deviceRecord ? 1 : 0,
                    'UserInfo' => $this->deviceRecord ? [$this->deviceRecord] : [],
                ]];
            });

        $client->shouldReceive('post')->with('/ISAPI/AccessControl/UserInfo/Record', Mockery::type('array'))
            ->andReturnUsing(function (string $path, array $payload): array {
                $this->deviceRecord = $payload['UserInfo'];

                return ['statusCode' => 1, 'statusString' => 'OK'];
            });

        $this->app->instance(DeviceManager::class, $manager);
        $this->app->forgetInstance(FingerprintEnrollmentService::class);
    }

    /** @param array<string, mixed> $captureResponse */
    private function enrollmentClient(array $captureResponse, array $slotResponse): void
    {
        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/SetUp', Mockery::type('array'))
            ->once()->andReturn($captureResponse);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/Search', Mockery::type('array'))
            ->once()->andReturn($slotResponse);

        $factory = Mockery::mock(DeviceClientFactory::class);
        $factory->shouldReceive('forTerminal')->andReturn($client);
        $this->app->instance(DeviceClientFactory::class, $factory);
        $this->app->forgetInstance(FingerprintEnrollmentService::class);
    }

    public function test_enrollment_is_confirmed_only_after_the_slot_is_read_back(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();
        $this->provisioner($terminal);
        $this->enrollmentClient(
            ['FingerPrintStatus' => ['status' => 'success', 'StatusList' => [['cardReaderRecvStatus' => 1]]]],
            ['FingerPrintSearch' => ['MatchList' => [['fingerPrintID' => 2, 'fingerType' => 'normalFP']]]],
        );

        $service = $this->app->make(FingerprintEnrollmentService::class);
        $enrollment = $service->start($person, $terminal, 2, $admin);

        $this->assertSame(FingerprintEnrollment::STATUS_PENDING, $enrollment->status);
        Queue::assertPushed(EnrollFingerprintJob::class);

        // A queued request is not success. Only processing records a verdict.
        $service->process($enrollment);
        $enrollment->refresh();

        $this->assertSame(FingerprintEnrollment::STATUS_CONFIRMED, $enrollment->status);
        $this->assertNotNull($enrollment->finished_at);
        $this->assertStringNotContainsString('fingerData', (string) $enrollment->device_response);
        $this->assertSame($person->id, $enrollment->personnel_id);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'enrollment_confirmed', 'subject_id' => $person->id]);
        $this->assertSame(0, Personnel::count() - 1, 'Enrollment must not create a second local person.');
    }

    public function test_a_cancelled_enrollment_is_not_overwritten_by_a_late_capture(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();
        $this->provisioner($terminal);

        $factory = Mockery::mock(DeviceClientFactory::class);
        $this->app->instance(DeviceClientFactory::class, $factory);
        $this->app->forgetInstance(FingerprintEnrollmentService::class);

        $service = $this->app->make(FingerprintEnrollmentService::class);
        $enrollment = $service->start($person, $terminal, 1, $admin);

        $service->cancel($enrollment, $admin);
        $this->assertSame(FingerprintEnrollment::STATUS_CANCELLED, $enrollment->fresh()->status);

        // Even if the worker runs afterwards, it must not claim success.
        $service->process($enrollment->fresh());

        $this->assertSame(FingerprintEnrollment::STATUS_CANCELLED, $enrollment->fresh()->status);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'enrollment_cancelled', 'outcome' => 'warning']);
        $this->assertDatabaseMissing('access_audit_logs', ['action' => 'enrollment_confirmed']);
    }

    public function test_a_poor_quality_capture_is_recorded_as_failed(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();
        $this->provisioner($terminal);

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/SetUp', Mockery::type('array'))
            ->once()->andReturn(['FingerPrintStatus' => ['status' => 'failed', 'StatusList' => [['cardReaderRecvStatus' => 4]]]]);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/Search', Mockery::type('array'))->never();

        $factory = Mockery::mock(DeviceClientFactory::class);
        $factory->shouldReceive('forTerminal')->andReturn($client);
        $this->app->instance(DeviceClientFactory::class, $factory);
        $this->app->forgetInstance(FingerprintEnrollmentService::class);

        $service = $this->app->make(FingerprintEnrollmentService::class);
        $enrollment = $service->start($person, $terminal, 3, $admin);
        $service->process($enrollment);
        $enrollment->refresh();

        $this->assertSame(FingerprintEnrollment::STATUS_FAILED, $enrollment->status);
        $this->assertStringContainsString('quality', (string) $enrollment->message);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'enrollment_failed', 'outcome' => 'failure']);
    }

    public function test_an_offline_terminal_cannot_be_asked_to_capture(): void
    {
        $admin = User::factory()->create();
        $terminal = $this->terminal('offline');
        $person = $this->person();

        $this->expectException(ValidationException::class);

        try {
            $this->app->make(FingerprintEnrollmentService::class)->start($person, $terminal, 1, $admin);
        } finally {
            $this->assertDatabaseCount('fingerprint_enrollments', 0);
        }
    }

    public function test_enrollment_falls_back_to_fingerprint_upload_when_search_is_unsupported(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $terminal = $this->terminal();
        $person = $this->person();
        $this->provisioner($terminal);

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/SetUp', Mockery::type('array'))
            ->once()->andReturn(['FingerPrintStatus' => ['status' => 'success']]);

        // First attempt calls FingerPrint/Search which fails with 400 notSupport
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/Search', Mockery::type('array'))
            ->once()->andThrow(new \RuntimeException('HTTP request failed: Client error: `POST http://192.168.60.41/ISAPI/AccessControl/FingerPrint/Search?format=json` resulted in a `400 Bad Request` response: {"statusCode":4,"subStatusCode":"notSupport"}'));

        // Fallback calls FingerPrintUpload which succeeds with the slot
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', Mockery::type('array'))
            ->once()->andReturn([
                'FingerPrintInfo' => [
                    'status' => 'OK',
                    'FingerPrintList' => [
                        ['cardReaderNo' => 1, 'fingerPrintID' => 1, 'fingerType' => 'normalFP'],
                    ],
                ],
            ]);

        $factory = Mockery::mock(DeviceClientFactory::class);
        $factory->shouldReceive('forTerminal')->andReturn($client);
        $this->app->instance(DeviceClientFactory::class, $factory);
        $this->app->forgetInstance(FingerprintEnrollmentService::class);

        $service = $this->app->make(FingerprintEnrollmentService::class);
        $enrollment = $service->start($person, $terminal, 1, $admin);

        $service->process($enrollment);
        $enrollment->refresh();

        $this->assertSame(FingerprintEnrollment::STATUS_CONFIRMED, $enrollment->status);
        $this->assertNotNull($enrollment->finished_at);
    }

    public function test_fingerprint_upload_returns_empty_when_employee_has_no_fingerprint(): void
    {
        $terminal = $this->terminal();

        $client = Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrint/Search', Mockery::type('array'))
            ->once()->andThrow(new \RuntimeException('HTTP request failed: Client error: `POST http://192.168.60.41/ISAPI/AccessControl/FingerPrint/Search?format=json` resulted in a `400 Bad Request` response: {"statusCode":4,"subStatusCode":"notSupport"}'));

        $client->shouldReceive('post')->with('/ISAPI/AccessControl/FingerPrintUpload', Mockery::type('array'))
            ->once()->andThrow(new \RuntimeException('HTTP request failed: Client error: `POST http://192.168.60.41/ISAPI/AccessControl/FingerPrintUpload?format=json` resulted in a `400 Bad Request` response: {"statusCode":6,"subStatusCode":"fingerprintNotLinkEmployeeIdOrCard"}'));

        $factory = Mockery::mock(DeviceClientFactory::class);
        $factory->shouldReceive('forTerminal')->andReturn($client);
        $this->app->instance(DeviceClientFactory::class, $factory);
        $this->app->forgetInstance(FingerprintEnrollmentService::class);

        $service = $this->app->make(FingerprintEnrollmentService::class);
        $slots = $service->fingerprintSlots($terminal, '00232');

        $this->assertSame([], $slots);
    }

    public function test_an_unapproved_existing_identity_cannot_be_reused_for_capture(): void
    {
        Queue::fake();
        $terminal = $this->terminal();
        $person = $this->person();
        $this->provisioner($terminal);
        $this->deviceRecord = ['employeeNo' => $person->employee_no, 'userType' => 'normal', 'Valid' => ['enable' => false]];
        $this->expectExceptionMessage('Existing identity is not confirmed blocked or locally authorized');
        app(FingerprintEnrollmentService::class)->start($person, $terminal, 1);
    }
}
