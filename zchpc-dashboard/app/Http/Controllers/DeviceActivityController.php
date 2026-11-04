<?php

namespace App\Http\Controllers;

use App\Models\DeviceActivityLog;
use App\Models\Terminal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceActivityController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('devices.view');

        $filters = $this->filters($request);

        return view('devices.activity', [
            'logs' => $this->query($filters)->paginate(100)->withQueryString(),
            'filters' => $filters,
            'terminals' => Terminal::query()->orderBy('name')->get(['id', 'name']),
            'actions' => DeviceActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        Gate::authorize('devices.view');

        $filters = $this->filters($request);
        $timezone = (string) config('attendance.timezone');

        return response()->streamDownload(function () use ($filters, $timezone): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Timestamp', 'Device', 'Action', 'Outcome', 'Administrator', 'Detail', 'Source address', 'Timezone',
            ], ',', '"', '');

            $this->query($filters)->chunk(500, function ($logs) use ($output, $timezone): void {
                foreach ($logs as $log) {
                    $values = [
                        $log->created_at?->copy()->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                        $log->terminal_name ?? 'All devices',
                        $log->actionLabel(),
                        $log->outcome,
                        $log->user_name ?? 'System',
                        $log->message ?? '',
                        $log->ip_address ?? '',
                        $timezone,
                    ];
                    fputcsv($output, array_map($this->safeCsv(...), $values), ',', '"', '');
                }
            });

            fclose($output);
        }, 'device-activity-'.$filters['from']->format('Y-m-d').'-to-'.$filters['to']->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{terminal_id: int|null, action: string|null, outcome: string|null, search: string|null, from: Carbon, to: Carbon}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'terminal_id' => ['nullable', 'integer', 'exists:terminals,id'],
            'action' => ['nullable', 'string', 'max:40'],
            'outcome' => ['nullable', Rule::in(['success', 'failure', 'info'])],
            'search' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $timezone = (string) config('attendance.timezone');
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'], $timezone)->startOfDay()
            : now($timezone)->subDays(7)->startOfDay();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'], $timezone)->endOfDay()
            : now($timezone)->endOfDay();

        return [
            'terminal_id' => isset($validated['terminal_id']) ? (int) $validated['terminal_id'] : null,
            'action' => $validated['action'] ?? null,
            'outcome' => $validated['outcome'] ?? null,
            'search' => filled($validated['search'] ?? null) ? trim((string) $validated['search']) : null,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param  array{terminal_id: int|null, action: string|null, outcome: string|null, search: string|null, from: Carbon, to: Carbon}  $filters
     * @return Builder<DeviceActivityLog>
     */
    private function query(array $filters): Builder
    {
        return DeviceActivityLog::query()
            ->whereBetween('created_at', [$filters['from']->copy()->utc(), $filters['to']->copy()->utc()])
            ->orderByDesc('id')
            ->when($filters['terminal_id'] !== null, fn ($query) => $query->where('terminal_id', $filters['terminal_id']))
            ->when($filters['action'] !== null, fn ($query) => $query->where('action', $filters['action']))
            ->when($filters['outcome'] !== null, fn ($query) => $query->where('outcome', $filters['outcome']))
            ->when($filters['search'] !== null, function ($query) use ($filters): void {
                $like = '%'.$filters['search'].'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('message', 'like', $like)
                        ->orWhere('terminal_name', 'like', $like)
                        ->orWhere('user_name', 'like', $like);
                });
            });
    }

    /** Keep spreadsheet applications from interpreting stored text as a formula. */
    private function safeCsv(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-]/u', $value) || preg_match('/^[\t\r\n]/', $value)
            ? "'".$value
            : $value;
    }
}
