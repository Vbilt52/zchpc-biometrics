<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\Terminal;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

class EventImportService
{
    private const MAX_EVENTS_PER_IMPORT = 5000;

    public function __construct(private readonly DeviceManager $deviceManager) {}

    public function import(Terminal $terminal, Carbon $from, Carbon $to, array $namesByEmployeeNo): array
    {
        if (! $terminal->supports('event_import')) {
            throw new \RuntimeException("{$terminal->name} does not provide an event-log import API.");
        }

        $imported = 0;
        $updated = 0;
        $processed = 0;
        $truncated = false;

        foreach ($this->events($terminal, $from, $to) as $event) {
            if ($processed >= self::MAX_EVENTS_PER_IMPORT) {
                $truncated = true;
                break;
            }

            $processed++;
            $employeeNo = $this->eventValue($event, ['employeeNoString', 'employeeNo']);
            $personName = $this->eventValue($event, ['name', 'employeeName'])
                ?? ($employeeNo !== null ? ($namesByEmployeeNo[$employeeNo] ?? null) : null);

            $stored = AccessEvent::updateOrCreate(
                ['event_key' => $this->eventKey($terminal, $event)],
                [
                    'terminal_id' => $terminal->id,
                    'employee_no' => $employeeNo,
                    'person_name' => $personName,
                    'occurred_at' => $this->eventTime($event),
                    'major' => $this->eventInteger($event, 'major'),
                    'minor' => $this->eventInteger($event, 'minor'),
                    'verify_mode' => $this->eventValue($event, ['verifyMode', 'type']),
                    'event_type' => $this->eventValue($event, ['eventType']),
                    'raw_payload' => $event,
                ],
            );

            $stored->wasRecentlyCreated ? $imported++ : $updated++;
        }

        return compact('imported', 'updated', 'truncated');
    }

    /** @param array<string, mixed> $event */
    private function eventKey(Terminal $terminal, array $event): string
    {
        return hash('sha256', $terminal->id.'|'.json_encode($this->sortEvent($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $event */
    private function eventTime(array $event): ?Carbon
    {
        $time = $this->eventValue($event, ['time', 'Time']);

        if ($time === null) {
            return null;
        }

        try {
            return Carbon::parse($time, config('attendance.device_timezone'))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $event @param list<string> $keys */
    private function eventValue(array $event, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($event[$key]) && $event[$key] !== '') {
                return Str::limit((string) $event[$key], 128, '');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $event */
    private function eventInteger(array $event, string $key): ?int
    {
        return isset($event[$key]) && is_numeric($event[$key]) ? (int) $event[$key] : null;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function sortEvent(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortEvent($item);
            }
        }

        return $value;
    }

    protected function events(Terminal $terminal, Carbon $from, Carbon $to): \Generator
    {
        $client = $this->deviceManager->device($terminal->name);
        $searchId = bin2hex(random_bytes(8));
        $position = 0;
        do {
            $response = $client->post('/ISAPI/AccessControl/AcsEvent', ['AcsEventCond' => [
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => 100,
                'major' => 0,
                'minor' => 0,
                'startTime' => $from->copy()->setTimezone(config('attendance.device_timezone'))->toAtomString(),
                'endTime' => $to->copy()->setTimezone(config('attendance.device_timezone'))->toAtomString(),
            ]]);
            $block = $response['AcsEvent'] ?? null;
            $status = is_array($block) ? ($block['responseStatusStrg'] ?? null) : null;
            if (! in_array($status, ['OK', 'MORE', 'NO MATCH'], true)) {
                throw new \RuntimeException('Unrecognized event search response.');
            }
            $items = $block['InfoList'] ?? [];
            if (! is_array($items)) {
                throw new \RuntimeException('Invalid event list.');
            }
            $items = array_is_list($items) ? $items : [$items];
            if (($status === 'MORE' && $items === []) || ($status === 'NO MATCH' && $items !== [])) {
                throw new \RuntimeException('Incomplete event search response.');
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    throw new \RuntimeException('Invalid event record.');
                }
                yield $item;
            }
            $position += count($items);
        } while ($status === 'MORE');
    }
}
