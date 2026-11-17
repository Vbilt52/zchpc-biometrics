<?php

namespace Tests\Feature;

use App\Models\AccessAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_day_filters_and_csv_use_same_rows_and_safe_cells(): void
    {
        AccessAuditLog::create(['user_name' => '=formula', 'action' => 'personnel_updated', 'outcome' => 'info', 'description' => 'Included local midnight', 'created_at' => '2026-11-16 22:30:00']);
        AccessAuditLog::create(['user_name' => 'System', 'action' => 'personnel_updated', 'outcome' => 'info', 'description' => 'Previous local day', 'created_at' => '2026-11-16 21:30:00']);
        $filters = ['from' => '2026-11-17', 'to' => '2026-11-17'];
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('access.audit.index', $filters))->assertOk()->assertSee('Included local midnight')->assertDontSee('Previous local day')->assertSee('17 Nov 2026 00:30:00');
        $csv = $this->get(route('access.audit.download', $filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('Included local midnight', $csv);
        $this->assertStringNotContainsString('Previous local day', $csv);
        $this->assertStringContainsString("'=formula", $csv);
    }

    public function test_hr_actions_and_download_cannot_leak_other_account_history(): void
    {
        AccessAuditLog::create(['user_name' => 'System', 'action' => 'password_reset', 'outcome' => 'info', 'description' => 'Private account action', 'created_at' => now()]);
        AccessAuditLog::create(['user_name' => 'System', 'action' => 'personnel_updated', 'outcome' => 'info', 'description' => 'Personnel action', 'created_at' => now()]);
        $this->actingAs(User::factory()->hrOfficer()->create())->get(route('access.audit.index'))->assertOk()->assertSee('Personnel action')->assertDontSee('Private account action')->assertDontSee('password_reset');
        $csv = $this->get(route('access.audit.download'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Private account action', $csv);
    }

    public function test_reversed_date_range_is_rejected(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('access.audit.index', ['from' => '2026-11-18', 'to' => '2026-11-17']))->assertSessionHasErrors('to');
    }
}
