<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\AttendanceRegisterService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28T18:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_register_and_downloads_require_login(): void
    {
        foreach (['attendance.index', 'attendance.export.csv', 'attendance.export.pdf'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post('/attendance/1/checkout')->assertRedirect(route('login'));
    }

    public function test_it_records_the_fingerprint_owner_and_entry_without_assigning_an_exit_button_to_anyone(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, '0007', '2026-10-28T08:03:12+02:00', ['person_name' => 'Prince One']);
        $this->scan($terminal, '0007', '2026-10-28T12:10:05+02:00', ['person_name' => 'Prince One']);
        $this->scan($terminal, '0008', '2026-10-28T08:04:00+02:00', ['person_name' => 'Prince Two']);
        $this->scan($terminal, '0007', '2026-10-28T17:00:00+02:00', ['minor' => 28, 'verify_mode' => '']);
        $this->scan($terminal, '0008', '2026-10-28T17:01:00+02:00', ['minor' => 39, 'event_type' => 'AccessDenied']);
        $this->scan($terminal, '0008', '2026-10-28T17:02:00+02:00', ['minor' => 75, 'verify_mode' => 'face']);

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $prince = $rows->firstWhere('employee_no', '0007');
        $this->assertSame('Prince One', $prince['owner']);
        $this->assertSame('08:03:12', $prince['first_in']->format('H:i:s'));
        $this->assertSame('12:10:05', $prince['last_scan']->format('H:i:s'));
        $this->assertSame(2, $prince['scan_count']);
        $this->assertNull($prince['logout']);

        $this->actingAs(User::factory()->create())->get(route('attendance.index', $this->filters()))
            ->assertOk()->assertSee('Prince One')->assertSee('Prince Two')->assertSee('0007')
            ->assertSee('08:03:12')->assertSee('Not captured')->assertSee('exit button does not identify a person');
    }

    public function test_owner_identity_uses_employee_id_even_when_names_are_the_same(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, '001', '2026-10-28T08:00:00+02:00', ['person_name' => 'Same Name']);
        $this->scan($terminal, '002', '2026-10-28T08:01:00+02:00', ['person_name' => 'Same Name']);
        $this->assertCount(2, $this->rows());
    }

    public function test_owner_name_can_be_resolved_from_the_personnel_record(): void
    {
        Personnel::create(['employee_no' => '003', 'name' => 'Registered Owner']);
        $this->scan($this->terminal(), '003', '2026-10-28T08:00:00+02:00', ['person_name' => null]);
        $this->assertSame('Registered Owner', $this->rows()->first()['owner']);
    }

    public function test_raw_offsets_and_local_midnight_are_used_instead_of_the_old_import_wall_time(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, 'MIDNIGHT', '2026-10-27T22:15:00Z', [
            'occurred_at' => '2026-10-27 22:15:00',
        ]);
        $this->scan($terminal, 'YESTERDAY', '2026-10-27T21:59:00Z');
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('MIDNIGHT', $rows->first()['employee_no']);
        $this->assertSame('00:15:00', $rows->first()['first_in']->format('H:i:s'));
    }

    public function test_duplicate_payload_variants_do_not_add_another_fingerprint_scan(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, 'EMP-1', '2026-10-28T08:00:00+02:00');
        $this->scan($terminal, 'EMP-1', '2026-10-28T08:00:00+02:00');
        $this->assertSame(1, $this->rows()->first()['scan_count']);
    }

    public function test_system_events_and_explicit_denials_cannot_become_successful_fingerprint_entries(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, 'DENIED', '2026-10-28T08:00:00+02:00', ['event_type' => 'AccessDenied']);
        $this->scan($terminal, 'SYSTEM', '2026-10-28T08:01:00+02:00', ['major' => 6]);
        $this->scan($terminal, 'UNKNOWN', '2026-10-28T08:02:00+02:00', ['minor' => 100, 'event_type' => null]);
        $this->assertCount(0, $this->rows());
    }

    public function test_an_operator_can_record_logout_and_corrections_preserve_the_history(): void
    {
        $entry = $this->scan($this->terminal(), 'EMP-1', '2026-10-28T08:00:00+02:00');
        $operator = User::factory()->create(['name' => 'Security Operator']);
        $url = route('attendance.checkout', ['accessEvent' => $entry->id] + $this->filters());
        $this->actingAs($operator)->post($url, [
            'checked_out_at' => '2026-10-28T17:00:00', 'reason' => 'Departure confirmed by security desk.',
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($operator)->post($url, [
            'checked_out_at' => '2026-10-28T17:05:00', 'reason' => 'Corrected against the signed gate register.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseCount('attendance_checkouts', 2);
        $row = $this->rows()->first();
        $this->assertSame('17:05:00', $row['logout']->format('H:i:s'));
        $this->assertSame('Security Operator', $row['checkout']->recorded_by_name);
        $this->assertSame('recorded', $row['status']);
        $this->assertCount(2, $row['checkout_history']);
        $this->assertSame('15:05:00', $row['checkout']->checked_out_at->format('H:i:s'));
    }

    public function test_logout_before_latest_entry_in_the_future_or_without_a_reason_is_rejected(): void
    {
        $entry = $this->scan($this->terminal(), 'EMP-1', '2026-10-28T09:00:00+02:00');
        $this->actingAs(User::factory()->create());
        $url = route('attendance.checkout', ['accessEvent' => $entry->id] + $this->filters());
        foreach (['2026-10-28T08:59:59', '2026-10-28T18:00:01'] as $logout) {
            $this->post($url, ['checked_out_at' => $logout, 'reason' => 'Confirmed departure'])
                ->assertSessionHasErrors('checked_out_at');
        }
        $this->post($url, ['checked_out_at' => '2026-10-28T17:00:00', 'reason' => ' '])
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('attendance_checkouts', 0);
    }

    public function test_a_later_fingerprint_scan_flags_a_previous_logout_for_review(): void
    {
        $terminal = $this->terminal();
        $entry = $this->scan($terminal, 'EMP-1', '2026-10-28T08:00:00+02:00');
        $this->actingAs(User::factory()->create())->post(route('attendance.checkout', ['accessEvent' => $entry->id] + $this->filters()), [
            'checked_out_at' => '2026-10-28T12:00:00', 'reason' => 'Departure confirmed by security.',
        ])->assertSessionHas('success');
        $this->scan($terminal, 'EMP-1', '2026-10-28T13:00:00+02:00');
        $this->assertSame('review', $this->rows()->first()['status']);
        $this->assertCount(1, $this->rows(['status' => 'review']));
    }

    public function test_filtered_csv_and_pdf_include_the_same_owner_and_login_time(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, '001', '2026-10-28T08:01:02+02:00', ['person_name' => 'Selected Owner']);
        $this->scan($terminal, '002', '2026-10-28T09:00:00+02:00', ['person_name' => 'Other Owner']);
        $filters = $this->filters(['search' => 'Selected']);
        $this->actingAs(User::factory()->create());
        $this->get(route('attendance.index', $filters))->assertOk()->assertSee('Selected Owner')->assertDontSee('Other Owner');
        $csv = $this->get(route('attendance.export.csv', $filters));
        $csv->assertOk()->assertDownload('fingerprint-register-2026-10-28-to-2026-10-28.csv');
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Selected Owner', $content);
        $this->assertStringContainsString('08:01:02', $content);
        $this->assertStringContainsString('Not captured (exit button)', $content);
        $this->assertStringNotContainsString('Other Owner', $content);
        $pdf = $this->get(route('attendance.export.pdf', $filters));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_csv_protects_formula_text_and_preserves_leading_zero_ids(): void
    {
        $this->scan($this->terminal(), '0007', '2026-10-28T08:00:00+02:00', ['person_name' => '=1+2']);
        $csv = $this->actingAs(User::factory()->create())->get(route('attendance.export.csv', $this->filters()))->streamedContent();
        $this->assertStringContainsString("'=1+2", $csv);
        $this->assertStringContainsString("'0007", $csv);
    }

    public function test_register_rejects_invalid_or_excessively_large_ranges(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('attendance.index', ['from' => '2026-10-28', 'to' => '2026-10-27']))->assertSessionHasErrors('to');
        $this->get(route('attendance.index', ['from' => '2026-01-01', 'to' => '2026-10-28']))->assertSessionHasErrors('to');
        config(['attendance.max_events' => 1]);
        $terminal = $this->terminal();
        $this->scan($terminal, 'EMP-1', '2026-10-28T08:00:00+02:00');
        $this->scan($terminal, 'EMP-2', '2026-10-28T08:01:00+02:00');
        $this->get(route('attendance.index', $this->filters()))->assertSessionHasErrors('from');
    }

    public function test_terminal_deletion_preserves_its_imported_history(): void
    {
        $terminal = $this->terminal();
        $this->scan($terminal, 'EMP-1', '2026-10-28T08:00:00+02:00');
        $this->actingAs(User::factory()->create())->delete(route('terminals.destroy', $terminal))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('terminals', ['id' => $terminal->id]);
        $this->assertDatabaseCount('access_events', 1);
    }

    public function test_terminal_and_department_filters_apply_to_the_register_and_download(): void
    {
        $selected = $this->terminal();
        $other = $this->terminal();
        $group = AccessGroup::firstOrFail();
        Personnel::create(['employee_no' => 'MATCH', 'name' => 'Department Owner', 'access_group_id' => $group->id]);
        $this->scan($selected, 'MATCH', '2026-10-28T08:00:00+02:00');
        $this->scan($other, 'MATCH', '2026-10-28T08:01:00+02:00');
        $this->scan($selected, 'OTHER', '2026-10-28T08:02:00+02:00');
        $filters = $this->filters(['terminal_id' => $selected->id, 'access_group_id' => $group->id]);
        $this->assertCount(1, $this->rows($filters));
        $csv = $this->actingAs(User::factory()->create())->get(route('attendance.export.csv', $filters))->streamedContent();
        $this->assertStringContainsString($selected->name, $csv);
        $this->assertStringNotContainsString($other->name, $csv);
        $this->assertStringNotContainsString('Owner OTHER', $csv);
    }

    public function test_an_earlier_backfilled_scan_does_not_lose_the_owners_manual_logout(): void
    {
        $terminal = $this->terminal();
        $entry = $this->scan($terminal, 'EMP-1', '2026-10-28T09:00:00+02:00');
        $this->actingAs(User::factory()->create())->post(route('attendance.checkout', ['accessEvent' => $entry->id] + $this->filters()), [
            'checked_out_at' => '2026-10-28T17:00:00', 'reason' => 'Departure confirmed by security.',
        ])->assertSessionHas('success');
        $this->scan($terminal, 'EMP-1', '2026-10-28T08:00:00+02:00');
        $row = $this->rows()->first();
        $this->assertSame('08:00:00', $row['first_in']->format('H:i:s'));
        $this->assertSame('17:00:00', $row['logout']->format('H:i:s'));
        $this->assertSame('recorded', $row['status']);
    }

    private function filters(array $overrides = []): array
    {
        return array_replace([
            'from' => '2026-10-28', 'to' => '2026-10-28', 'terminal_id' => null,
            'search' => '', 'access_group_id' => null, 'status' => '',
        ], $overrides);
    }

    private function rows(array $overrides = [])
    {
        return app(AttendanceRegisterService::class)->rows($this->filters($overrides));
    }

    private function terminal(): Terminal
    {
        return Terminal::create([
            'name' => 'Entrance '.Str::uuid(), 'phase' => 'Phase 1', 'ip' => '192.0.2.1',
            'port' => 80, 'username' => 'admin', 'password' => 'test-secret',
            'protocol' => 'http', 'timeout' => 5, 'verify_ssl' => false,
        ]);
    }

    private function scan(Terminal $terminal, string $employeeNo, string $time, array $overrides = []): AccessEvent
    {
        return AccessEvent::create(array_replace([
            'terminal_id' => $terminal->id, 'event_key' => hash('sha256', (string) Str::uuid()),
            'employee_no' => $employeeNo, 'person_name' => 'Owner '.$employeeNo,
            'occurred_at' => CarbonImmutable::parse($time)->utc(), 'major' => 5, 'minor' => 38,
            'verify_mode' => 'fingerprint', 'event_type' => 'AccessGranted', 'raw_payload' => ['time' => $time],
        ], $overrides));
    }
}
