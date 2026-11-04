<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\AttendanceCheckout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceRegisterService
{
    public function eventTime(AccessEvent $event): ?CarbonImmutable
    {
        $raw = $event->raw_payload ?? [];
        $time = $raw['time'] ?? $raw['Time'] ?? null;

        try {
            // Device offsets survive here even in older imports whose database
            // datetime was stored without its original timezone information.
            if (is_string($time) && trim($time) !== '') {
                return CarbonImmutable::parse($time, config('attendance.device_timezone'))
                    ->setTimezone(config('attendance.timezone'));
            }

            return $event->occurred_at
                ? CarbonImmutable::instance($event->occurred_at)->setTimezone(config('attendance.timezone'))
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** One row per fingerprint owner, terminal and local calendar day. */
    public function rows(array $filters): Collection
    {
        $zone = config('attendance.timezone');
        $from = CarbonImmutable::parse($filters['from'], $zone)->startOfDay();
        $to = CarbonImmutable::parse($filters['to'], $zone)->endOfDay();

        $query = AccessEvent::with(['terminal', 'personnel.accessGroup'])
            ->whereNotNull('employee_no')->where('employee_no', '!=', '')
            // Include a buffer for older imports stored as device wall time.
            ->whereBetween('occurred_at', [$from->utc()->subDay(), $to->utc()->addDay()])
            ->where(function ($query): void {
                $query->where('major', 5)->orWhere(function ($query): void {
                    $query->whereNull('major')->where('event_type', 'AccessGranted');
                });
            });

        if ($filters['terminal_id'] !== null) {
            $query->where('terminal_id', $filters['terminal_id']);
        }

        $limit = (int) config('attendance.max_events');
        $events = $query->orderBy('occurred_at')->orderBy('id')->limit($limit + 1)->get();
        if ($events->count() > $limit) {
            throw ValidationException::withMessages([
                'from' => 'Too many scans for this register. Choose a shorter date range or one terminal.',
            ]);
        }

        $groups = [];
        foreach ($events as $event) {
            if (! $event->isSuccessfulFingerprint()) {
                continue;
            }

            $at = $this->eventTime($event);
            if ($at === null || $at->lt($from) || $at->gt($to)) {
                continue;
            }

            $date = $at->toDateString();
            $key = $this->key($event->terminal_id, $event->employee_no, $date);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $date,
                    'terminal_id' => $event->terminal_id,
                    'terminal_name' => $event->terminal?->name ?? 'Removed terminal',
                    'employee_no' => $event->employee_no,
                    'owner' => $event->person_name ?: ($event->personnel?->name ?? 'Unmatched employee'),
                    'department' => $event->personnel?->accessGroup?->name ?? $event->personnel?->department,
                    'access_group_id' => $event->personnel?->access_group_id,
                    'scans' => [],
                ];
            }

            // Changed optional payload fields must not double-count the same scan.
            $groups[$key]['scans'][$at->format('U.u')] ??= ['at' => $at, 'event_id' => $event->id];
        }

        $checkouts = AttendanceCheckout::whereBetween('attendance_date', [$filters['from'], $filters['to']])
            ->when($filters['terminal_id'] !== null, fn ($query) => $query->where('terminal_id', $filters['terminal_id']))
            ->orderByDesc('id')->get()
            ->groupBy(fn (AttendanceCheckout $record) => $this->key(
                $record->terminal_id, $record->employee_no, $record->attendance_date,
            ));

        return collect($groups)->map(function (array $row, string $key) use ($checkouts, $zone): array {
            $scans = collect($row['scans'])->sortBy(fn ($scan) => $scan['at']->format('U.u'))->values();
            $history = $checkouts->get($key, collect());
            $checkout = $history->first();
            $row['scans'] = $scans;
            $row['first_in'] = $scans->first()['at'];
            $row['last_scan'] = $scans->last()['at'];
            $row['first_event_id'] = $scans->first()['event_id'];
            $row['scan_count'] = $scans->count();
            $row['checkout'] = $checkout;
            $row['checkout_history'] = $history;
            $row['logout'] = $checkout?->checked_out_at->setTimezone($zone);
            $row['status'] = $checkout === null ? 'missing'
                : ($row['logout']->lt($row['last_scan']) ? 'review' : 'recorded');

            return $row;
        })->filter(function (array $row) use ($filters): bool {
            if (($filters['access_group_id'] ?? null) !== null
                && $row['access_group_id'] !== $filters['access_group_id']) {
                return false;
            }
            if (($filters['status'] ?? '') !== '' && $row['status'] !== $filters['status']) {
                return false;
            }
            $search = trim($filters['search'] ?? '');

            return $search === '' || mb_stripos($row['owner'], $search) !== false
                || mb_stripos($row['employee_no'], $search) !== false;
        })->sort(function (array $left, array $right): int {
            return strcmp($right['date'], $left['date'])
                ?: strnatcasecmp($left['owner'], $right['owner'])
                ?: strcmp($left['employee_no'], $right['employee_no'])
                ?: strcmp($left['terminal_name'], $right['terminal_name']);
        })->values();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'recorded' => 'Logout recorded manually',
            'review' => 'New entry after recorded logout',
            default => 'Logout not captured',
        };
    }

    private function key(int $terminalId, string $employeeNo, string $date): string
    {
        return json_encode([$terminalId, $employeeNo, $date], JSON_THROW_ON_ERROR);
    }
}
