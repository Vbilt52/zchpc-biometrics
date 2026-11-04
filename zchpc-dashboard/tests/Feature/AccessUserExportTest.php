<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\Door;
use App\Models\FingerprintEnrollment;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessUserExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Personnel $person;
    private Door $door;
    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $group = AccessGroup::create(['name' => 'IT Department', 'code' => 'IT']);

        $this->terminal = Terminal::create([
            'name' => 'Main Gate Controller',
            'ip' => '192.168.60.40',
            'port' => 80,
            'protocol' => 'http',
            'username' => 'admin',
            'password' => 'secret123',
            'timeout' => 5,
            'verify_ssl' => false,
            'status' => 'online',
        ]);

        $this->door = Door::create([
            'name' => 'Server Room Entrance',
            'code' => 'SR-01',
            'building' => 'Building A',
            'terminal_id' => $this->terminal->id,
            'is_active' => true,
        ]);

        $this->person = Personnel::create([
            'employee_no' => '00987',
            'name' => 'Tinashe Chikwanha',
            'access_group_id' => $group->id,
            'status' => Personnel::STATUS_ACTIVE,
            'user_type' => 'normal',
            'valid_enabled' => true,
            'phone_number' => '+263771234567',
            'email' => 'tinashe@zchpc.ac.zw',
        ]);

        $this->person->doors()->attach($this->door->id);
        $this->person->terminals()->attach($this->terminal->id, [
            'access_allowed' => true,
            'sync_status' => 'confirmed',
        ]);

        FingerprintEnrollment::create([
            'employee_no' => $this->person->employee_no,
            'person_name' => $this->person->name,
            'personnel_id' => $this->person->id,
            'terminal_id' => $this->terminal->id,
            'terminal_name' => $this->terminal->name,
            'finger_id' => 1,
            'status' => FingerprintEnrollment::STATUS_CONFIRMED,
            'finished_at' => now(),
        ]);
    }

    public function test_guest_cannot_download_access_lists(): void
    {
        $this->get(route('access.users.export.csv'))->assertRedirect(route('login'));
        $this->get(route('access.users.export.pdf'))->assertRedirect(route('login'));
        $this->get(route('access.users.export.word'))->assertRedirect(route('login'));
    }

    public function test_admin_can_download_csv_showing_devices_and_doors(): void
    {
        $response = $this->actingAs($this->admin)->get(route('access.users.export.csv'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Employee ID', $content);
        $this->assertStringContainsString('Accessible Doors & Rooms', $content);
        $this->assertStringContainsString('Assigned Devices / Terminals', $content);
        $this->assertStringContainsString('Tinashe Chikwanha', $content);
        $this->assertStringContainsString('Server Room Entrance', $content);
        $this->assertStringContainsString('Main Gate Controller', $content);
        $this->assertStringContainsString('192.168.60.40', $content);
        // Formula injection protection and leading zero preservation
        $this->assertStringContainsString("'00987", $content);
    }

    public function test_admin_can_download_pdf_report(): void
    {
        $response = $this->actingAs($this->admin)->get(route('access.users.export.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF', $content);
    }

    public function test_admin_can_download_word_directory(): void
    {
        $response = $this->actingAs($this->admin)->get(route('access.users.export.word'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.ms-word');

        $content = $response->getContent();
        $this->assertStringContainsString('Zimbabwe Centre for High Performance Computing', $content);
        $this->assertStringContainsString('Tinashe Chikwanha', $content);
        $this->assertStringContainsString('Server Room Entrance', $content);
    }
}
