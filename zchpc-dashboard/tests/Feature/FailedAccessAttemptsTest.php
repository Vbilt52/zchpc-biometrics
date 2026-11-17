<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailedAccessAttemptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_failed_attempts_show_terminal_and_time_and_exports_keep_filter(): void
    {
        $terminal = Terminal::create(['name' => 'Server Room', 'phase' => 'Phase 1', 'ip' => '192.0.2.1', 'username' => 'admin', 'password' => 'test']);
        foreach ([[5, 39, null], [5, 38, 'Prince Granted'], [6, 39, 'System alarm']] as [$major, $minor, $name]) {
            AccessEvent::create(['terminal_id' => $terminal->id, 'event_key' => Str::random(64),
                'occurred_at' => '2026-10-29 06:15:00', 'major' => $major, 'minor' => $minor, 'person_name' => $name,
                'raw_payload' => ['time' => '2026-10-29T08:15:00+02:00']]);
        }
        $query = ['from' => '2026-10-29T00:00', 'to' => '2026-10-29T23:59', 'terminal_id' => $terminal->id];
        $this->get(route('events.failed'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('events.failed', $query))->assertOk()
            ->assertSee('Unknown person')->assertSee('Server Room')->assertSee('29 Oct 2026 08:15:00')
            ->assertDontSee('Prince Granted')->assertDontSee('System alarm')->assertSee('1 matching record(s)');
        $word = $this->get(route('events.export.word', $query + ['outcome' => 'denied']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'denied-report-');
        try {
            file_put_contents($path, $word->getContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('Server Room', $xml);
            $this->assertStringNotContainsString('Prince Granted', $xml);
            $zip->close();
        } finally {
            unlink($path);
        }
        $this->assertSame(1, AccessEvent::failedAttempt()->count());
    }
}
