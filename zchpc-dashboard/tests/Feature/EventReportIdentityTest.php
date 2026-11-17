<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;
use App\Services\TabularReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class EventReportIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_and_archived_names_match_exact_ids_in_every_report(): void
    {
        $user = User::factory()->create();
        $terminal = Terminal::create(['name' => 'Main Entrance', 'ip' => '192.0.2.1', 'port' => 80, 'username' => 'test', 'password' => 'test']);
        Door::create(['name' => 'Reception Entry', 'code' => 'REC', 'terminal_id' => $terminal->id, 'door_no' => 1, 'is_active' => true]);
        $person = Personnel::create(['employee_no' => '0007', 'name' => 'Correct & Full Name']);
        $person->delete();
        AccessEvent::create(['terminal_id' => $terminal->id, 'employee_no' => '0007', 'person_name' => 'Old Device Name', 'event_key' => 'one', 'occurred_at' => now(), 'major' => 5, 'minor' => 38, 'raw_payload' => ['doorNo' => 1, 'inOutType' => 'in']]);
        $filters = ['search' => 'Correct & Full', 'personnel_id' => $person->id, 'to' => now()->addMinute()->toIso8601String()];
        $this->actingAs($user)->get(route('events.index', $filters))->assertOk()->assertSee('Correct &amp; Full Name', false)->assertDontSee('Old Device Name')->assertSee('Reception Entry')->assertSee('Entry');

        foreach (['word' => 'word/document.xml', 'excel' => 'xl/sharedStrings.xml'] as $format => $part) {
            $response = $this->actingAs($user)->get(route('events.export.'.$format, $filters))->assertOk();
            $path = tempnam(sys_get_temp_dir(), 'report-test-');
            try {
                file_put_contents($path, $response->getContent());
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($path));
                $xml = $zip->getFromName($part);
                $this->assertStringContainsString('Correct &amp; Full Name', $xml);
                $this->assertStringContainsString($person->employee_id, $xml);
                $this->assertStringContainsString('Reception Entry', $xml);
                $this->assertStringNotContainsString('Old Device Name', $xml);
                $zip->close();
            } finally {
                unlink($path);
            }
        }
        $this->actingAs($user)->get(route('events.export.pdf', $filters))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_system_minor_codes_and_unknown_directions_are_not_claimed_as_entries(): void
    {
        $event = new AccessEvent(['major' => 1, 'minor' => 38]);
        $this->assertSame('Info', $event->outcomeLabel());
        $this->assertSame('Not reported', $event->directionLabel());
        $this->assertSame('Unknown person', $event->displayName());
    }

    public function test_excel_strings_cannot_be_formulas(): void
    {
        $response = app(TabularReportExporter::class)->download('xlsx', 'test', 'Test', 'Test', ['Name'], [['=HYPERLINK("https://example.com")']]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        try {
            file_put_contents($path, $response->getContent());
            $book = IOFactory::load($path);
            $this->assertSame('s', $book->getActiveSheet()->getCell('A4')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
