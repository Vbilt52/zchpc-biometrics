<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\AccessGroup;
use App\Models\AttendanceCheckout;
use App\Models\Terminal;
use App\Services\AttendanceRegisterService;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceRegisterService $register) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $rows = $this->register->rows($filters);
        $summary = [
            'owners' => $rows->pluck('employee_no')->uniqueStrict()->count(),
            'scans' => $rows->sum('scan_count'),
            'missing' => $rows->where('status', '!=', 'recorded')->count(),
        ];
        $page = LengthAwarePaginator::resolveCurrentPage();
        $entries = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, [
            'path' => route('attendance.index'), 'query' => $request->query(),
        ]);

        return view('attendance.index', [
            'entries' => $entries, 'summary' => $summary, 'filters' => $filters,
            'terminals' => Terminal::orderBy('name')->get(['id', 'name']),
            'accessGroups' => AccessGroup::orderBy('name')->get(),
            'timezone' => config('attendance.timezone'),
            'register' => $this->register,
        ]);
    }

    public function checkout(Request $request, AccessEvent $accessEvent): RedirectResponse
    {
        $returnFilters = $this->filters($request);
        $validated = $request->validate([
            'checked_out_at' => ['required', 'date_format:Y-m-d\TH:i:s'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        abort_unless($accessEvent->isSuccessfulFingerprint(), 422, 'Select a successful fingerprint entry.');
        $entryTime = $this->register->eventTime($accessEvent);
        abort_if($entryTime === null, 422, 'This entry has no valid timestamp.');
        $filters = [
            'from' => $entryTime->toDateString(), 'to' => $entryTime->toDateString(),
            'terminal_id' => $accessEvent->terminal_id, 'search' => '',
            'access_group_id' => null, 'status' => '',
        ];
        $row = $this->register->rows($filters)->firstWhere('employee_no', $accessEvent->employee_no);
        abort_if($row === null, 422, 'The fingerprint entry is not available in the register.');

        $logout = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s', $validated['checked_out_at'], config('attendance.timezone'));
        if ($logout->lt($row['last_scan']) || $logout->gt(CarbonImmutable::now())
            || $logout->gt($row['first_in']->addHours(36))) {
            throw ValidationException::withMessages([
                'checked_out_at' => 'Logout must be at or after the latest fingerprint scan, not in the future, and within 36 hours of the first entry.',
            ]);
        }

        AttendanceCheckout::create([
            'terminal_id' => $row['terminal_id'], 'employee_no' => $row['employee_no'],
            'attendance_date' => $row['date'], 'checked_out_at' => $logout->utc(),
            'last_entry_at' => $row['last_scan']->utc(), 'person_name' => $row['owner'],
            'terminal_name' => $row['terminal_name'], 'recorded_by' => $request->user()->id,
            'recorded_by_name' => $request->user()->name, 'reason' => trim($validated['reason']),
        ]);

        return redirect()->route('attendance.index', $returnFilters)
            ->with('success', 'Logout recorded for '.$row['owner'].'. Earlier records remain in the history.');
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->register->rows($filters);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Date', 'Fingerprint owner', 'Employee ID', 'Terminal', 'Department', 'Login time', 'Latest fingerprint scan', 'Logout time', 'Logout source', 'Recorded by', 'Reason', 'Status', 'Fingerprint scans', 'Timezone'], ',', '"', '');
            foreach ($rows as $row) {
                $values = [
                    $row['date'], $row['owner'], $row['employee_no'], $row['terminal_name'], $row['department'] ?? '',
                    $row['first_in']->format('Y-m-d H:i:s'), $row['last_scan']->format('Y-m-d H:i:s'),
                    $row['logout']?->format('Y-m-d H:i:s') ?? '',
                    $row['checkout'] ? 'Manual operator record' : 'Not captured (exit button)',
                    $row['checkout']?->recorded_by_name ?? '', $row['checkout']?->reason ?? '',
                    $this->register->statusLabel($row['status']), (string) $row['scan_count'], config('attendance.timezone'),
                ];
                fputcsv($output, array_map($this->safeCsv(...), $values), ',', '"', '');
            }
            fclose($output);
        }, 'fingerprint-register-'.$filters['from'].'-to-'.$filters['to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function exportPdf(Request $request): Response
    {
        $filters = $this->filters($request);
        $rows = $this->register->rows($filters);
        if ($rows->count() > config('attendance.max_pdf_rows')) {
            throw ValidationException::withMessages(['from' => 'PDF downloads support up to 500 rows. Narrow the filters or download CSV.']);
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('attendance.report', [
            'rows' => $rows, 'filters' => $filters, 'register' => $this->register,
            'timezone' => config('attendance.timezone'),
        ])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="fingerprint-register-'.$filters['from'].'-to-'.$filters['to'].'.pdf"',
        ]);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'terminal_id' => ['nullable', 'integer', 'exists:terminals,id'],
            'access_group_id' => ['nullable', 'integer', 'exists:access_groups,id'],
            'search' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'in:missing,recorded,review'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $today = CarbonImmutable::now(config('attendance.timezone'))->toDateString();
        $from = $validated['from'] ?? $today;
        $to = $validated['to'] ?? $from;
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start, with a range of at most 93 calendar days.']);
        }

        return [
            'from' => $from, 'to' => $to,
            'terminal_id' => isset($validated['terminal_id']) ? (int) $validated['terminal_id'] : null,
            'access_group_id' => isset($validated['access_group_id']) ? (int) $validated['access_group_id'] : null,
            'search' => trim($validated['search'] ?? ''), 'status' => $validated['status'] ?? '',
        ];
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-]/u', $value) || preg_match('/^[\t\r\n]/', $value)
            || preg_match('/^0[0-9]+$/', $value) ? "'".$value : $value;
    }
}
