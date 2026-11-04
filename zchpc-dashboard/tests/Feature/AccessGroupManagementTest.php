<?php

namespace Tests\Feature;

use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_groups_are_available_and_a_persons_group_can_be_changed(): void
    {
        $user = User::factory()->create();
        $supercomputing = AccessGroup::where('name', 'Supercomputing')->firstOrFail();
        $applications = AccessGroup::where('name', 'Applications')->firstOrFail();

        $this->actingAs($user)
            ->get(route('access-groups.index'))
            ->assertOk()
            ->assertSee('Supercomputing')
            ->assertSee('Applications')
            ->assertSee('Administration')
            ->assertSee('ZINGSA');

        $response = $this->actingAs($user)
            ->post(route('personnel.store'), $this->personData($supercomputing->id));

        $person = Personnel::where('employee_no', 'EMP-001')->firstOrFail();
        $response->assertRedirect(route('personnel.show', $person));
        $this->assertSame($supercomputing->id, $person->access_group_id);
        $this->assertSame('Supercomputing', $person->department);

        $this->actingAs($user)
            ->get(route('personnel.edit', $person))
            ->assertOk()
            ->assertSee('name="access_group_id"', false)
            ->assertSee('Supercomputing');

        $this->actingAs($user)
            ->put(route('personnel.update', $person), $this->personData($applications->id, 'EMP-001', 'Updated Person'))
            ->assertRedirect(route('personnel.show', $person));

        $person->refresh();
        $this->assertSame($applications->id, $person->access_group_id);
        $this->assertSame('Applications', $person->department);
    }

    /** @return array<string, mixed> */
    private function personData(int $accessGroupId, string $employeeNo = 'EMP-001', string $name = 'Test Person'): array
    {
        return [
            'employee_no' => $employeeNo,
            'name' => $name,
            'user_type' => 'normal',
            'valid_enabled' => '1',
            'begin_time' => now()->startOfDay()->format('Y-m-d H:i:s'),
            'end_time' => now()->addYear()->endOfDay()->format('Y-m-d H:i:s'),
            'access_group_id' => $accessGroupId,
            'doors' => [1],
        ];
    }
}
