<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\EventImportService;
use App\Services\PersonnelDeviceAccessService;
use App\Services\PersonnelDeviceIdentity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Tests\TestCase;

class PersonnelDeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function terminal(string $name): Terminal
    {
        return Terminal::create(['name' => $name, 'ip' => '192.0.2.1', 'username' => 'test', 'password' => 'test']);
    }

    public function test_link_keeps_one_person_and_resolves_old_and_new_events_only_on_the_confirmed_terminal(): void
    {
        $person = Personnel::create(['employee_no' => '44', 'name' => 'Sharon Example']);
        $main = $this->terminal('Main');
        $other = $this->terminal('Other');
        $raw = ['employeeNoString' => '189', 'name' => 'Sharon', 'major' => 5, 'minor' => 38, 'time' => now()->toIso8601String()];
        $old = AccessEvent::create(['terminal_id' => $main->id, 'employee_no' => '189', 'event_key' => 'old', 'raw_payload' => $raw]);
        $unrelated = AccessEvent::create(['terminal_id' => $other->id, 'employee_no' => '189', 'event_key' => 'other', 'raw_payload' => $raw]);
        PersonnelDeviceIdentity::link($person, $main, '189');
        $this->assertSame('44', $old->fresh()->employee_no);
        $this->assertSame($raw, $old->fresh()->raw_payload);
        $this->assertSame('189', $unrelated->fresh()->employee_no);
        $this->assertSame($person->id, PersonnelDeviceIdentity::resolve($main, '189')->id);
        $this->assertNull(PersonnelDeviceIdentity::resolve($other, '189'));
        $service = new class(app(DeviceManager::class), $raw) extends EventImportService
        {
            public function __construct($manager, private array $raw)
            {
                parent::__construct($manager);
            }

            protected function events(Terminal $terminal, Carbon $from, Carbon $to): \Generator
            {
                yield $this->raw;
            }
        };
        $service->import($main, now()->subDay(), now(), []);
        $event = AccessEvent::whereNotIn('event_key', ['old', 'other'])->firstOrFail();
        $this->assertSame('44', $event->employee_no);
        $this->assertSame('Sharon Example', $event->displayName());
        $this->assertSame($person->employee_id, $event->personnel->employee_id);
        $this->assertSame($raw, $event->raw_payload);
        $this->assertDatabaseCount('personnel', 1);
        $person->delete();
        $this->assertTrue(PersonnelDeviceIdentity::resolve($main, '189')->trashed());
    }

    public function test_device_import_skips_exclusions_and_reuses_the_existing_alias_owner(): void
    {
        $person = Personnel::create(['employee_no' => '44', 'name' => 'Sharon Example']);
        $terminal = $this->terminal('Main');
        PersonnelDeviceIdentity::link($person, $terminal, '189');
        DB::table('personnel_device_exclusions')->insert([
            'terminal_id' => $terminal->id, 'employee_no' => '90', 'reason' => 'Operator excluded archived identity',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $client = \Mockery::mock(HikvisionClient::class);
        $client->shouldReceive('post')->once()->andReturn(['UserInfoSearch' => ['responseStatusStrg' => 'OK', 'UserInfo' => [
            ['employeeNo' => '189', 'name' => 'Sharon', 'userType' => 'normal'],
            ['employeeNo' => '90', 'name' => 'Excluded Person', 'userType' => 'normal'],
        ]]]);
        $manager = \Mockery::mock(DeviceManager::class);
        $manager->shouldReceive('device')->andReturn($client);
        $this->app->instance(DeviceManager::class, $manager);
        $this->actingAs(User::factory()->create())->post(route('personnel.import', $terminal))->assertSessionHas('success');
        $this->assertDatabaseCount('personnel', 1);
        $this->assertSame('Sharon Example', $person->fresh()->name);
        $this->assertTrue($person->terminals()->whereKey($terminal->id)->exists());
        $this->delete(route('terminals.destroy', $terminal))->assertSessionHas('error');
        $this->assertDatabaseHas('terminals', ['id' => $terminal->id]);
        $this->assertFalse(PersonnelDeviceIdentity::excluded($this->terminal('Other'), '90'));
    }

    public function test_a_link_cannot_steal_another_current_or_archived_device_identity(): void
    {
        $person = Personnel::create(['employee_no' => '44', 'name' => 'One']);
        $other = Personnel::create(['employee_no' => '189', 'name' => 'Two']);
        $other->delete();
        $this->expectException(ValidationException::class);
        PersonnelDeviceIdentity::link($person, $this->terminal('Main'), '189');
    }

    public function test_importing_an_alias_as_a_new_person_is_rejected(): void
    {
        $person = Personnel::create(['employee_no' => '44', 'name' => 'One']);
        PersonnelDeviceIdentity::link($person, $this->terminal('Main'), '189');
        $this->expectException(ValidationException::class);
        Personnel::create(['employee_no' => '189', 'name' => 'Duplicate']);
    }

    public function test_held_id_cannot_misattribute_old_or_new_events_to_another_person(): void
    {
        $person = Personnel::create(['employee_no' => '180', 'name' => 'Main Entrance Person']);
        $main = $this->terminal('Main');
        $other = $this->terminal('Other');
        $raw = ['employeeNoString' => '180', 'name' => 'Different Device Person', 'major' => 5, 'minor' => 38, 'time' => now()->toIso8601String()];
        $old = AccessEvent::create(['terminal_id' => $other->id, 'employee_no' => '180', 'event_key' => 'held-old', 'raw_payload' => $raw]);
        PersonnelDeviceIdentity::hold($other, '180', 'Operator requested conflicting identities remain unchanged.');
        $this->assertNull($old->fresh()->personnel);
        $this->assertSame('Different Device Person', $old->fresh()->displayName());
        $this->assertSame('Device ID 180 · identity unconfirmed', $old->fresh()->employeeLabel());
        $this->assertSame($raw, $old->fresh()->raw_payload);
        $this->assertSame($person->id, PersonnelDeviceIdentity::resolve($main, '180')->id);
        $this->assertNull(PersonnelDeviceIdentity::resolve($other, '180'));
        $service = new class(app(DeviceManager::class), $raw) extends EventImportService
        {
            public function __construct($manager, private array $raw)
            {
                parent::__construct($manager);
            }

            protected function events(Terminal $terminal, Carbon $from, Carbon $to): \Generator
            {
                yield $this->raw;
            }
        };
        $service->import($other, now()->subDay(), now(), ['180' => $person->name]);
        $new = AccessEvent::where('event_key', '!=', 'held-old')->firstOrFail();
        $this->assertNull($new->personnel);
        $this->assertSame('Different Device Person', $new->displayName());
    }

    public function test_held_identity_removal_stays_pending_without_device_writes(): void
    {
        $person = Personnel::create(['employee_no' => '180', 'name' => 'Main Person']);
        $terminal = $this->terminal('Other');
        $person->terminals()->attach($terminal->id, ['access_allowed' => true, 'sync_status' => 'confirmed']);
        PersonnelDeviceIdentity::hold($terminal, '180', 'Different device owner');
        $manager = \Mockery::mock(DeviceManager::class);
        $manager->shouldNotReceive('device');
        $service = new PersonnelDeviceAccessService($manager);
        $service->remove($person);
        $result = $service->syncNow($person);
        $this->assertSame(['synced' => 0, 'pending' => 1], $result);
        $this->assertDatabaseHas('pending_terminal_syncs', ['personnel_id' => $person->id, 'terminal_id' => $terminal->id, 'operation' => 'delete']);
    }
}
