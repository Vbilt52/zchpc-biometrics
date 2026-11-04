<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use App\Models\AccessEvent;
use App\Models\AccessGroup;
use App\Models\Door;
use App\Models\FingerprintEnrollment;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Services\AccessAuditLogger;
use App\Services\AccessWizard;
use App\Services\DeviceActivityLogger;
use App\Services\DoorAccessService;
use App\Services\DoorCatalog;
use App\Services\FingerprintEnrollmentService;
use App\Services\PersonnelPhotoService;
use App\Services\PersonnelProfileService;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessUserController extends Controller
{
    public function __construct(
        private readonly DoorAccessService $access,
        private readonly DoorCatalog $catalog,
        private readonly AccessWizard $wizard,
        private readonly AccessAuditLogger $audit,
        private readonly PersonnelProfileService $profiles,
        private readonly PersonnelPhotoService $photos,
        private readonly FingerprintEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('access.view');

        $search = trim((string) $request->query('search', ''));
        $department = $request->integer('department');
        $state = (string) $request->query('state', '');
        $sync = (string) $request->query('sync', '');

        $query = $this->filteredQuery($request);
        $people = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('access.index', [
            'people' => $people,
            'drafts' => Personnel::query()->where('status', Personnel::STATUS_DRAFT)
                ->with('accessGroup')->latest('updated_at')->limit(10)->get(),
            'departments' => AccessGroup::orderBy('name')->get(),
            'filters' => ['search' => $search, 'department' => $department ?: null, 'state' => $state, 'sync' => $sync],
            'stats' => [
                'people' => Personnel::query()->onboarded()->count(),
                'drafts' => Personnel::query()->where('status', Personnel::STATUS_DRAFT)->count(),
                'fingerprints' => FingerprintEnrollment::query()
                    ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)
                    ->whereNotNull('personnel_id')->distinct()->count('personnel_id'),
                'doors' => Door::query()->active()->count(),
                'pending' => PendingTerminalSync::query()->count(),
                'denied_today' => AccessEvent::query()->failedAttempt()
                    ->where('occurred_at', '>=', now()->startOfDay())->count(),
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        Gate::authorize('access.view');

        $people = $this->filteredQuery($request)
            ->with([
                'accessGroup',
                'terminals',
                'doors.terminal',
                'fingerprintEnrollments' => fn ($q) => $q->where('status', FingerprintEnrollment::STATUS_CONFIRMED),
                'pendingSyncs',
            ])
            ->orderBy('name')
            ->get();

        $filename = 'zchpc-access-permissions-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($people): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Employee ID',
                'Full Name',
                'Department',
                'User Role',
                'Access Status',
                'Fingerprint Status',
                'Doors Count',
                'Accessible Doors & Rooms',
                'Assigned Devices / Terminals',
                'Controller Sync Status',
                'Valid From',
                'Valid Until',
                'Phone Number',
                'Email',
                'Last Access Activity',
            ]);

            foreach ($people as $p) {
                $fpConfirmed = $p->fingerprintEnrollments;
                if ($fpConfirmed->isNotEmpty()) {
                    $slots = $fpConfirmed->pluck('finger_id')->unique()->sort()->values();
                    $fpLabel = 'Enrolled (Slot #'.$slots->implode(', #').')';
                } else {
                    $fpLabel = 'Not Enrolled';
                }

                $doorsList = $p->doors->map(function ($d) {
                    $building = $d->building ? " [{$d->building}]" : '';
                    $code = $d->code ? " ({$d->code})" : '';

                    return $d->name.$building.$code;
                })->implode('; ');

                $terminalsList = $p->terminals->where('pivot.access_allowed', true)->map(function ($t) {
                    return $t->name." ({$t->ip})";
                })->implode('; ');

                if ($p->pendingSyncs->isNotEmpty()) {
                    $syncStatus = 'Pending Sync ('.$p->pendingSyncs->count().' device(s))';
                } elseif ($p->terminals->where('pivot.access_allowed', true)->isNotEmpty()) {
                    $syncStatus = 'Confirmed on all controllers';
                } else {
                    $syncStatus = 'No device access';
                }

                $lastAccess = $p->last_access_at ? Carbon::parse($p->last_access_at)->format('Y-m-d H:i') : 'Never';

                fputcsv($out, [
                    $this->safeCsv($p->employee_no),
                    $p->name,
                    $p->accessGroup?->name ?? $p->department ?? 'General Staff',
                    $p->roleLabel(),
                    $p->accessStateLabel(),
                    $fpLabel,
                    $p->doors->count(),
                    $doorsList ?: 'No doors assigned',
                    $terminalsList ?: 'No devices assigned',
                    $syncStatus,
                    $p->begin_time ? $p->begin_time->format('Y-m-d H:i') : 'Immediate',
                    $p->end_time ? $p->end_time->format('Y-m-d H:i') : 'Permanent',
                    $this->safeCsv($p->phone_number ?? ''),
                    $p->email ?? '',
                    $lastAccess,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request): Response
    {
        Gate::authorize('access.view');

        $people = $this->filteredQuery($request)
            ->with([
                'accessGroup',
                'terminals',
                'doors.terminal',
                'fingerprintEnrollments' => fn ($q) => $q->where('status', FingerprintEnrollment::STATUS_CONFIRMED),
                'pendingSyncs',
            ])
            ->orderBy('name')
            ->get();

        $stats = [
            'total_users' => $people->count(),
            'active_users' => $people->filter(fn ($p) => $p->isActive())->count(),
            'fingerprint_enrolled' => $people->filter(fn ($p) => $p->fingerprintEnrollments->isNotEmpty())->count(),
            'total_doors' => Door::query()->active()->count(),
            'total_terminals' => Terminal::apiManaged()->count(),
        ];

        $filtersDesc = [];
        if ($request->filled('search')) {
            $filtersDesc[] = 'Search: "'.$request->query('search').'"';
        }
        if ($request->integer('department') > 0) {
            $deptName = AccessGroup::find($request->integer('department'))?->name;
            if ($deptName) {
                $filtersDesc[] = 'Department: '.$deptName;
            }
        }
        if ($request->filled('state')) {
            $filtersDesc[] = 'State: '.ucfirst($request->query('state'));
        }
        if ($request->query('sync') === 'pending') {
            $filtersDesc[] = 'Sync: Pending Writes';
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);

        $html = view('access.report-pdf', [
            'people' => $people,
            'stats' => $stats,
            'filterText' => empty($filtersDesc) ? 'All Users & Access Directory' : implode(' | ', $filtersDesc),
            'generatedAt' => now()->format('d M Y H:i:s'),
        ])->render();

        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        $filename = 'zchpc-user-access-report-'.now()->format('Y-m-d').'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function exportWord(Request $request): Response
    {
        Gate::authorize('access.view');

        $people = $this->filteredQuery($request)
            ->with([
                'accessGroup',
                'terminals',
                'doors.terminal',
                'fingerprintEnrollments' => fn ($q) => $q->where('status', FingerprintEnrollment::STATUS_CONFIRMED),
                'pendingSyncs',
            ])
            ->orderBy('name')
            ->get();

        $filename = 'zchpc-user-access-directory-'.now()->format('Y-m-d').'.doc';

        $html = view('access.report-word', [
            'people' => $people,
            'generatedAt' => now()->format('d M Y H:i:s'),
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-word',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function filteredQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $search = trim((string) $request->query('search', ''));
        $department = $request->integer('department');
        $state = (string) $request->query('state', '');
        $sync = (string) $request->query('sync', '');

        $query = Personnel::query()
            ->onboarded()
            ->with(['accessGroup', 'terminals'])
            ->withCount([
                'doors as granted_doors_count' => fn ($query) => $query
                    ->where('doors.is_active', true)
                    ->where(fn ($query) => $query->whereNull('personnel_door.expires_at')
                        ->orWhere('personnel_door.expires_at', '>', now())),
                'fingerprintEnrollments as confirmed_fingerprints_count' => fn ($query) => $query
                    ->where('status', FingerprintEnrollment::STATUS_CONFIRMED),
                'pendingSyncs as pending_sync_count',
            ])
            ->withMax('accessEvents as last_access_at', 'occurred_at');

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('employee_no', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone_number', 'like', '%'.$search.'%');
            });
        }

        if ($department > 0) {
            $query->where('access_group_id', $department);
        }

        match ($state) {
            'active' => $query->where('status', Personnel::STATUS_ACTIVE)
                ->where('valid_enabled', true)
                ->where('user_type', '!=', 'blocklist')
                ->where(fn ($query) => $query->whereNull('begin_time')->orWhere('begin_time', '<=', now()))
                ->where(fn ($query) => $query->whereNull('end_time')->orWhere('end_time', '>=', now())),
            'suspended' => $query->where('status', Personnel::STATUS_SUSPENDED),
            'expired' => $query->whereNotNull('end_time')->where('end_time', '<', now()),
            'blocked' => $query->where('user_type', 'blocklist'),
            'disabled' => $query->where('valid_enabled', false),
            'no_fingerprint' => $query->whereDoesntHave('fingerprintEnrollments', fn ($query) => $query
                ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)),
            default => null,
        };

        if ($sync === 'pending') {
            $query->whereHas('pendingSyncs');
        }

        return $query;
    }

    private function safeCsv(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/^[\s]*[=+@\-]/u', $value)) {
            return "'".$value;
        }

        if (preg_match('/^0[0-9]+$/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    public function show(Personnel $personnel): View
    {
        Gate::authorize('access.view');

        $personnel->load(['accessGroup', 'terminals', 'doors', 'pendingSyncs', 'fingerprintEnrollments', 'syncEvents']);

        $grantedDoors = $personnel->doors()
            ->with('terminal')
            ->orderBy('doors.building')->orderBy('doors.floor')->orderBy('doors.name')
            ->get();

        $audits = Gate::allows('access.audit')
            ? AccessAuditLog::query()
                ->where('subject_type', 'personnel')->where('subject_id', $personnel->id)
                ->latest('id')->limit(12)->get()
            : collect();

        return view('access.show', [
            'personnel' => $personnel,
            'controllerStates' => $this->access->controllerStates($personnel),
            'grantedDoors' => $grantedDoors,
            'availableDoors' => $this->catalog->groupedDoors(),
            'accessGroups' => AccessGroup::query()->with('doors')->orderBy('name')->get(),
            'history' => $personnel->accessEvents()->orderByDesc('occurred_at')->limit(15)->get(),
            'enrollments' => $personnel->fingerprintEnrollments()->latest('id')->limit(10)->get(),
            'audits' => $audits,
        ]);
    }

    public function edit(Personnel $personnel): View
    {
        Gate::authorize('access.manage');

        return view('access.edit', [
            'personnel' => $personnel,
            'departments' => AccessGroup::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $data = $request->validate($this->profiles->rules($personnel));

        $duplicates = $this->profiles->duplicates($data, $personnel->id);
        if ($duplicates->isNotEmpty() && ! (bool) ($data['confirm_duplicate'] ?? false)) {
            return back()->withInput()->with('duplicate_matches', $duplicates)
                ->withErrors(['confirm_duplicate' => 'Possible duplicate people were found. Review the list, then confirm this is a different person.']);
        }

        $this->profiles->updateProfile(
            $personnel,
            $data,
            $request->file('photo'),
            $request->user(),
            DeviceActivityLogger::requestIp(),
        );

        if ($personnel->isDraft()) {
            return redirect()->route('access.users.show', $personnel)
                ->with('success', 'Draft details saved. Continue with device selection when you are ready.');
        }

        $sync = $this->access->syncPersonnel($personnel->refresh(), $request->user());

        return redirect()->route('access.users.show', $personnel)
            ->with('success', 'Profile saved. '.$this->syncSentence($sync));
    }

    public function photo(Personnel $personnel): Response
    {
        Gate::authorize('access.view');

        return $this->photos->response($personnel);
    }

    public function enrollForm(Personnel $personnel): View
    {
        Gate::authorize('access.enroll');

        return view('access.enroll', [
            'personnel' => $personnel,
            'terminals' => Terminal::apiManaged()->orderBy('phase')->orderBy('name')->get(),
            'enrollments' => $personnel->fingerprintEnrollments()->latest('id')->limit(10)->get(),
            'activeEnrollment' => $personnel->fingerprintEnrollments()->active()->latest('id')->first(),
            'fingerIds' => (array) config('access.enrollment.finger_ids', range(1, 10)),
            'profile' => true,
        ]);
    }

    public function startEnrollment(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.enroll');

        $validated = $request->validate([
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'finger_id' => ['required', 'integer', 'between:1,10'],
        ]);

        $terminal = Terminal::apiManaged()->findOrFail($validated['terminal_id']);

        try {
            $this->enrollments->start($personnel, $terminal, (int) $validated['finger_id'],
                $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->withInput()->with('error', $error->getMessage());
        }

        return redirect()->route('access.users.enroll', $personnel)
            ->with('success', 'Enrollment requested. Ask '.$personnel->name.' to place a finger on '.$terminal->name.'.');
    }

    public function cancelEnrollment(Request $request, Personnel $personnel, FingerprintEnrollment $enrollment): RedirectResponse
    {
        Gate::authorize('access.enroll');
        $this->ownsEnrollment($personnel, $enrollment);

        try {
            $this->enrollments->cancel($enrollment, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return redirect()->route('access.users.enroll', $personnel)->with('success', 'Enrollment cancelled.');
    }

    public function retryEnrollment(Request $request, Personnel $personnel, FingerprintEnrollment $enrollment): RedirectResponse
    {
        Gate::authorize('access.enroll');
        $this->ownsEnrollment($personnel, $enrollment);

        $terminal = $enrollment->terminal;
        if ($terminal === null) {
            return back()->with('error', 'That terminal no longer exists.');
        }

        try {
            $this->enrollments->start($personnel, $terminal, (int) $enrollment->finger_id,
                $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return redirect()->route('access.users.enroll', $personnel)
            ->with('success', 'Enrollment retried. Ask '.$personnel->name.' to use the terminal now.');
    }

    public function grantDoors(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $validated = $request->validate([
            'door_ids' => ['required', 'array', 'min:1', 'max:100'],
            'door_ids.*' => ['integer', 'distinct', 'exists:doors,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'confirm' => ['accepted'],
        ]);

        try {
            $result = $this->access->grant(
                $personnel,
                array_map('intval', $validated['door_ids']),
                $request->user(),
                isset($validated['expires_at'])
                    ? Carbon::parse($validated['expires_at'], config('attendance.timezone'))->utc()
                    : null,
                null,
                DeviceActivityLogger::requestIp(),
            );
        } catch (\Throwable $error) {
            return back()->withInput()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Door access saved. '.$this->syncSentence($result));
    }

    public function revokeDoors(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $validated = $request->validate([
            'door_ids' => ['required', 'array', 'min:1', 'max:100'],
            'door_ids.*' => ['integer', 'distinct'],
            'confirm' => ['accepted'],
        ]);

        try {
            $result = $this->access->revoke($personnel, array_map('intval', $validated['door_ids']),
                $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Door access revoked. '.$this->syncSentence($result));
    }

    public function sync(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $result = $this->access->syncPersonnel($personnel, $request->user());

        return back()->with('success', 'Synchronization attempted. '.$this->syncSentence($result));
    }

    public function suspend(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $request->validate(['confirm' => ['accepted']]);

        try {
            $result = $this->access->suspend($personnel, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', $personnel->name.' is suspended. '.$this->syncSentence($result)
            .' Door grants were retained for a controlled reactivation.');
    }

    public function reactivate(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $request->validate(['confirm' => ['accepted']]);

        try {
            $result = $this->access->reactivate($personnel, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', $personnel->name.' reactivated. '.$this->syncSentence($result)
            .' Only doors still granted to this person were restored.');
    }

    public function destroy(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $validated = $request->validate([
            'confirm_employee_no' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        if ($validated['confirm_employee_no'] !== $personnel->employee_no) {
            return back()->with('error', 'The typed employee ID does not match. Nothing was deleted.');
        }

        try {
            $this->access->deleteUser($personnel, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', 'Local record retained. '.$error->getMessage());
        }

        return redirect()->route('access.index')->with('success', $personnel->name.' was removed after every controller confirmed deletion.');
    }

    public function discardDraft(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $request->validate(['confirm' => ['accepted']]);

        try {
            $this->access->discardDraft($personnel, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', 'Draft retained. '.$error->getMessage());
        }

        if ($this->wizard->personId() === $personnel->id) {
            $this->wizard->forget();
        }

        return redirect()->route('access.index')->with('success', 'Draft discarded.');
    }

    private function ownsEnrollment(Personnel $personnel, FingerprintEnrollment $enrollment): void
    {
        abort_unless((int) $enrollment->personnel_id === (int) $personnel->id, 404);
    }

    /** @param array{terminals: array, synced: int, pending: int} $result */
    private function syncSentence(array $result): string
    {
        $count = is_array($result['terminals']) ? count($result['terminals']) : (int) $result['terminals'];

        if ($count === 0) {
            return 'No controller needs to change.';
        }

        return $result['synced'].' of '.$count.' controller(s) confirmed; '.$result['pending'].' pending.';
    }
}
