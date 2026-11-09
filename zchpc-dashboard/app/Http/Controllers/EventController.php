<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\TerminalEventSync;
use App\Services\EventImportService;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(private readonly EventImportService $importer) {}

    /**
     * Show only locally imported audit events. This keeps user searches fast and
     * avoids making a hardware request every time an auditor changes a filter.
     */
    public function index(Request $request): View
    {
        if ($request->routeIs('events.failed')) {
            $request->merge(['outcome' => 'denied']);
        }
        $filters = $this->filters($request);

        $events = $this->filteredEvents($filters)
            ->with(['terminal', 'personnel'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        $terminals = Terminal::orderBy('phase')->orderBy('name')->get();
        $doors = Door::with('terminal')->orderBy('name')->get();
        $doorsByTerminalDoor = $doors->keyBy(fn ($d) => $d->terminal_id.'_'.$d->door_no);
        $personnel = Personnel::orderBy('name')->get(['id', 'employee_no', 'name']);
        $syncStates = TerminalEventSync::all()->keyBy('terminal_id');

        return view('events', compact('events', 'terminals', 'doors', 'doorsByTerminalDoor', 'personnel', 'filters', 'syncStates'));
    }

    /**
     * Read a selected terminal's log and persist a deduplicated local audit
     * copy. The import does not delete records, so subsequent searches and
     * reports remain available even if the terminal is offline.
     */
    public function import(Request $request): RedirectResponse
    {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $validated = $request->validate([
            'import_terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'import_from' => ['required', 'date'],
            'import_to' => ['required', 'date', 'after_or_equal:import_from'],
        ]);
        $filters = [
            'terminal_id' => (int) $validated['import_terminal_id'],
            // An import must never inherit an accidental person search: every
            // selected-terminal import stores records for all people.
            'personnel_id' => null,
            'search' => null,
            'from' => Carbon::parse($validated['import_from'], config('attendance.timezone')),
            'to' => Carbon::parse($validated['import_to'], config('attendance.timezone')),
        ];
        $terminal = Terminal::findOrFail($filters['terminal_id']);
        if (! $terminal->supports('event_import')) {
            return back()->withInput()->with('error', "{$terminal->name} does not provide an event-log import API. Select a Hikvision ISAPI terminal instead.");
        }
        try {
            $result = $this->importer->import($terminal, $filters['from'], $filters['to'], Personnel::pluck('name', 'employee_no')->all());
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Log import from {$terminal->name} failed: ".$this->terminalError($e));
        }

        $message = "Imported {$result['imported']} new log record(s) from {$terminal->name}; {$result['updated']} existing record(s) refreshed.";
        if ($result['truncated']) {
            $message .= ' The import stopped at 5,000 records; choose a shorter date range to import the remainder.';
        }

        return redirect()->route('events.index', $this->filterQuery($filters))
            ->with('success', $message);
    }

    /** Import every configured terminal, retaining successful imports if one device is unavailable. */
    public function importAll(Request $request): RedirectResponse
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $validated = $request->validate([
            'import_from' => ['required', 'date'],
            'import_to' => ['required', 'date', 'after_or_equal:import_from'],
        ]);
        $filters = [
            'terminal_id' => null,
            'personnel_id' => null,
            'search' => null,
            'from' => Carbon::parse($validated['import_from'], config('attendance.timezone')),
            'to' => Carbon::parse($validated['import_to'], config('attendance.timezone')),
        ];
        $namesByEmployeeNo = Personnel::pluck('name', 'employee_no')->all();
        $successful = [];
        $failed = [];

        foreach (Terminal::apiManaged()->orderBy('phase')->orderBy('name')->get() as $terminal) {
            try {
                $result = $this->importer->import($terminal, $filters['from'], $filters['to'], $namesByEmployeeNo);
                $successful[] = "{$terminal->name}: {$result['imported']} new, {$result['updated']} refreshed";
                if ($result['truncated']) {
                    $failed[] = "{$terminal->name}: stopped at 5,000 records; import a shorter date range for the remainder";
                }
            } catch (\Throwable $e) {
                $failed[] = "{$terminal->name}: ".$this->terminalError($e);
            }
        }

        $message = $successful === []
            ? 'No terminal logs could be imported.'
            : 'Log import completed — '.implode('; ', $successful).'.';
        $redirect = redirect()->route('events.index', $this->filterQuery($filters));

        if ($failed !== []) {
            return $redirect->with('success', $message)->with('error', 'Some terminals need attention — '.implode('; ', $failed));
        }

        return $redirect->with('success', $message);
    }

    public function exportPdf(Request $request): Response
    {
        $filters = $this->filters($request);
        $events = $this->reportEvents($filters);

        $html = view('events.report', [
            'events' => $events,
            'filters' => $filters,
            'format' => 'pdf',
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="zchpc-access-event-log.pdf"',
        ]);
    }

    /**
     * Word opens a standards-compliant HTML document natively. This avoids a
     * proprietary client dependency while producing an editable document.
     */
    public function exportWord(Request $request): Response
    {
        $filters = $this->filters($request);
        $html = view('events.report', [
            'events' => $this->reportEvents($filters),
            'filters' => $filters,
            'format' => 'word',
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'application/msword; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="zchpc-access-event-log.doc"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * @return array{terminal_id: int|null, door_id: int|null, personnel_id: int|null, search: string|null, from: Carbon, to: Carbon}
     */
    private function filters(Request $request, bool $terminalRequired = false): array
    {
        $validated = $request->validate([
            'terminal_id' => [$terminalRequired ? 'required' : 'nullable', 'integer', 'exists:terminals,id'],
            'door_id' => ['nullable', 'integer', 'exists:doors,id'],
            'personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
            'search' => ['nullable', 'string', 'max:64'],
            'outcome' => ['nullable', 'in:all,denied'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'], config('attendance.timezone')) : now(config('attendance.timezone'))->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'], config('attendance.timezone')) : now(config('attendance.timezone'));

        if ($to->lt($from)) {
            abort(422, 'The end of the log range must not be before its start.');
        }

        return [
            'terminal_id' => isset($validated['terminal_id']) ? (int) $validated['terminal_id'] : null,
            'door_id' => isset($validated['door_id']) ? (int) $validated['door_id'] : null,
            'personnel_id' => isset($validated['personnel_id']) ? (int) $validated['personnel_id'] : null,
            'search' => filled($validated['search'] ?? null) ? trim($validated['search']) : null,
            'outcome' => $validated['outcome'] ?? 'all',
            'from' => $from,
            'to' => $to,
        ];
    }

    /** @param array{terminal_id: int|null, door_id: int|null, personnel_id: int|null, search: string|null, from: Carbon, to: Carbon} $filters */
    private function filteredEvents(array $filters): Builder
    {
        $query = AccessEvent::query()->whereBetween('occurred_at', [$filters['from']->copy()->utc(), $filters['to']->copy()->utc()]);
        if (($filters['outcome'] ?? 'all') === 'denied') {
            $query->failedAttempt();
        }

        if (! empty($filters['door_id'])) {
            $door = Door::find($filters['door_id']);
            if ($door) {
                $query->where('terminal_id', $door->terminal_id)
                    ->where(function (Builder $q) use ($door): void {
                        $q->where('raw_payload->doorNo', (int) $door->door_no)
                            ->orWhere('raw_payload->doorNo', (string) $door->door_no);
                    });
            }
        } elseif ($filters['terminal_id'] !== null) {
            $query->where('terminal_id', $filters['terminal_id']);
        }

        if ($filters['personnel_id'] !== null) {
            $employeeNo = Personnel::whereKey($filters['personnel_id'])->value('employee_no');
            $query->where('employee_no', $employeeNo ?? '');
        }

        if ($filters['search'] !== null) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('person_name', 'like', $term)
                    ->orWhere('employee_no', 'like', $term);
            });
        }

        return $query;
    }

    /** @param array{terminal_id: int|null, door_id: int|null, personnel_id: int|null, search: string|null, from: Carbon, to: Carbon} $filters */
    private function reportEvents(array $filters): Collection
    {
        return $this->filteredEvents($filters)
            ->with('terminal')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array{terminal_id: int|null, door_id: int|null, personnel_id: int|null, search: string|null, from: Carbon, to: Carbon}  $filters
     * @return array<string, int|string>
     */
    private function filterQuery(array $filters): array
    {
        return array_filter([
            'terminal_id' => $filters['terminal_id'],
            'door_id' => $filters['door_id'] ?? null,
            'personnel_id' => $filters['personnel_id'],
            'search' => $filters['search'],
            'outcome' => $filters['outcome'] ?? 'all',
            'from' => $filters['from']->format('Y-m-d\TH:i'),
            'to' => $filters['to']->format('Y-m-d\TH:i'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function terminalError(\Throwable $error): string
    {
        $message = $error->getMessage();

        if (str_contains($message, 'cURL error 67')) {
            return 'the terminal rejected its saved credentials (cURL error 67). Open Terminals, edit this terminal, and save the correct device username and password before importing.';
        }

        if (str_contains($message, 'cURL error 7') || str_contains($message, 'Couldn\'t connect')) {
            return 'the terminal is offline or unreachable. Its log import will work after the device and network connection are restored.';
        }

        return $message;
    }
}
