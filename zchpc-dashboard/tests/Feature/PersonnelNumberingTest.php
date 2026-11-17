<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\User;
use App\Services\PersonnelNumbering;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersonnelNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_correction_numbers_current_users_and_drafts_without_restoring_archives(): void
    {
        $archived = Personnel::create(['name' => 'Archived Person', 'employee_no' => 'old']);
        $archived->delete();
        $active = Personnel::create(['name' => 'Current Person', 'employee_no' => 'current']);
        $draft = Personnel::create(['name' => 'Draft Person', 'employee_no' => 'draft', 'status' => Personnel::STATUS_DRAFT]);
        $migration = require database_path('migrations/2026_11_17_210000_number_only_current_personnel.php');
        $migration->up();
        $this->assertNull($archived->fresh()->personnel_code);
        $this->assertTrue($archived->fresh()->trashed());
        $this->assertSame('HPC001', $active->fresh()->employee_id);
        $this->assertSame('HPC002', $draft->fresh()->employee_id);
        $this->assertSame('HPC003', PersonnelNumbering::preview());
        $this->assertSame('current', $active->fresh()->employee_no);
        $this->assertSame(3, Personnel::withTrashed()->count());
    }

    public function test_numbers_are_reserved_and_device_identity_is_preserved(): void
    {
        $person = Personnel::create(['name' => 'First Person', 'employee_no' => '0007']);
        $this->assertSame('HPC001', $person->employee_id);
        $this->assertSame('0007', $person->employee_no);
        $person->delete();
        $this->assertSame('HPC002', PersonnelNumbering::preview());
        $second = Personnel::create(['name' => 'Second Person', 'employee_no' => '__AUTO__']);
        $this->assertSame('HPC002', $second->employee_id);
        $this->assertSame('HPC002', $second->employee_no);
    }

    public function test_insert_failure_rolls_back_number_reservation(): void
    {
        Personnel::create(['name' => 'First Person', 'employee_no' => 'EXISTING']);
        try {
            Personnel::create(['name' => 'Duplicate Person', 'employee_no' => 'EXISTING']);
            $this->fail('Duplicate device ID was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('personnel_number_sequences')->value('last_number'));
        }
    }

    public function test_two_forms_with_same_preview_get_distinct_numbers_and_validate_job_title(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('personnel.create'))->assertOk()->assertSee('HPC001');
        $this->get(route('personnel.create'))->assertOk()->assertSee('HPC001');
        $data = ['employee_no' => '__AUTO__', 'name' => 'New Person', 'job_title' => 'Systems Analyst',
            'user_type' => 'normal', 'valid_enabled' => '1', 'begin_time' => '2026-01-01T00:00',
            'end_time' => '2030-12-31T23:59', 'access_group_id' => AccessGroup::first()->id];
        $this->post(route('personnel.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('personnel.store'), array_replace($data, ['name' => 'Another Person']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('personnel', ['employee_no' => 'HPC001', 'personnel_code' => 'HPC001', 'job_title' => 'Systems Analyst']);
        $this->assertDatabaseHas('personnel', ['employee_no' => 'HPC002', 'personnel_code' => 'HPC002']);
        $this->post(route('personnel.store'), array_replace($data, ['job_title' => str_repeat('x', 121)]))->assertSessionHasErrors('job_title');
    }
}
