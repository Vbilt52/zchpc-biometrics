<?php

namespace Tests\Feature;

use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_updated_terminals_use_a_five_second_timeout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('terminals.store'), $this->terminalData())
            ->assertRedirect(route('terminals.index'));

        $terminal = Terminal::where('name', 'Test Entrance')->firstOrFail();
        $this->assertSame(5, $terminal->timeout);

        $this->actingAs($user)
            ->put(route('terminals.update', $terminal), $this->terminalData('Updated Entrance'))
            ->assertRedirect(route('terminals.index'));

        $terminal->refresh();
        $this->assertSame('Updated Entrance', $terminal->name);
        $this->assertSame(5, $terminal->timeout);
    }

    /** @return array<string, string> */
    private function terminalData(string $name = 'Test Entrance'): array
    {
        return [
            'name' => $name,
            'phase' => 'Phase 1',
            'ip' => '192.168.60.10',
            'port' => '80',
            'username' => 'admin',
            'password' => 'secret',
            'integration' => Terminal::INTEGRATION_HIKVISION_ISAPI,
            'protocol' => 'http',
        ];
    }

    public function test_standalone_readers_are_not_treated_as_hikvision_api_terminals(): void
    {
        $reader = Terminal::create([
            'name' => 'Standalone Reader',
            'phase' => 'Phase 1',
            'ip' => '192.168.60.90',
            'port' => 80,
            'username' => 'not-applicable',
            'password' => 'not-applicable',
            'protocol' => 'http',
            'timeout' => 5,
            'verify_ssl' => false,
            'integration' => Terminal::INTEGRATION_STANDALONE_WIEGAND,
            'model' => 'CTD fingerprint reader',
        ]);

        $this->assertFalse($reader->isApiManaged());
        $this->assertFalse($reader->supports('door_control'));
        $this->assertSame([], $reader->capabilities());
    }
}
