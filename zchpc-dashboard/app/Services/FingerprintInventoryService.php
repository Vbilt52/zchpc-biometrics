<?php

namespace App\Services;

use App\Models\FingerprintInventory;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Support\Collection;
use Shaykhnazar\HikvisionIsapi\Services\PersonService;

class FingerprintInventoryService
{
    public function __construct(
        private readonly DeviceClientFactory $clients,
        private readonly FingerprintEnrollmentService $fingerprints,
    ) {}

    /**
     * Read the device-held slot numbers for known personnel. The device owns all
     * biometric templates; this service persists only slot numbers and timing.
     *
     * @param  Collection<int, Terminal>|null  $terminals
     * @return array{terminals:int, people:int, slots:int, unavailable:int, errors:int}
     */
    public function refresh(?Collection $terminals = null): array
    {
        $result = ['terminals' => 0, 'people' => 0, 'slots' => 0, 'unavailable' => 0, 'errors' => 0];
        $terminals ??= Terminal::apiManaged()->orderBy('id')->get();

        foreach ($terminals as $terminal) {
            if (! $terminal->isOnline()) {
                $result['unavailable']++;

                continue;
            }

            try {
                $client = $this->clients->forTerminal($terminal, 8);
                $service = new PersonService($client);
                $result['terminals']++;

                foreach ($service->all(pageSize: 30) as $devicePerson) {
                    $person = PersonnelDeviceIdentity::resolve($terminal, (string) $devicePerson->employeeNo);
                    if (! $person || $person->trashed() || $person->isDraft()) {
                        continue;
                    }

                    try {
                        $slots = $this->fingerprints->fingerprintSlots($terminal, (string) $devicePerson->employeeNo);
                        sort($slots);
                        FingerprintInventory::updateOrCreate(
                            ['personnel_id' => $person->id, 'terminal_id' => $terminal->id],
                            ['device_employee_no' => (string) $devicePerson->employeeNo, 'slots' => $slots,
                                'checked_at' => now(), 'last_error' => null],
                        );
                        $result['people']++;
                        $result['slots'] += count($slots);
                    } catch (\Throwable) {
                        // Keep the last verified result. The terminal may be momentarily busy.
                        FingerprintInventory::where('personnel_id', $person->id)->where('terminal_id', $terminal->id)
                            ->update(['last_error' => 'Fingerprint status could not be read from the terminal.', 'updated_at' => now()]);
                        $result['errors']++;
                    }
                }
            } catch (\Throwable) {
                $result['unavailable']++;
            }
        }

        return $result;
    }
}
