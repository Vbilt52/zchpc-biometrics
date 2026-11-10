<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Services\AccessAuditLogger;
use App\Services\DeviceActivityLogger;
use App\Services\PendingTerminalSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\Services\FingerprintService;
use Shaykhnazar\HikvisionIsapi\Services\PersonService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonnelController extends Controller
{
    public function __construct(
        private readonly DeviceManager $deviceManager,
        private readonly PendingTerminalSyncService $pendingSyncs,
        private readonly ?AccessAuditLogger $audit = null,
    ) {}

    private function audit(): AccessAuditLogger
    {
        return $this->audit ?? app(AccessAuditLogger::class);
    }

    // =========================================================================
    // LISTING
    // =========================================================================

    public function index(Request $request): View
    {
        $query = Personnel::with(['terminals', 'accessGroup']);
        if ($terminalId = $request->integer('terminal_id')) {
            $query->whereHas('terminals', fn ($query) => $query->where('terminals.id', $terminalId)->where('personnel_terminals.access_allowed', true));
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->where('user_type', $role);
        }

        if ($accessGroupId = $request->integer('access_group')) {
            $query->where('access_group_id', $accessGroupId);
        }

        // Sort in clean numerical order (1, 2, 3 ... 145)
        $query->orderByRaw('CAST(employee_no AS INTEGER) ASC')->orderBy('employee_no', 'ASC');

        $personnel = $query->paginate(20)->withQueryString();
        $terminals = Terminal::apiManaged()->orderBy('phase')->get();
        $accessGroups = AccessGroup::orderBy('name')->get();
        $noTerminals = $terminals->isEmpty();

        return view('personnel.index', compact('personnel', 'terminals', 'accessGroups', 'noTerminals'));
    }

    // =========================================================================
    // CREATE / STORE
    // =========================================================================

    public function create(): View
    {
        $terminals = Terminal::apiManaged()->orderBy('phase')->get();
        $accessGroups = AccessGroup::orderBy('name')->get();

        return view('personnel.create', compact('terminals', 'accessGroups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_no' => 'required|string|max:32|unique:personnel,employee_no',
            'name' => 'required|string|max:64',
            'user_type' => 'required|in:normal,visitor,blocklist',
            'valid_enabled' => 'required|boolean',
            'begin_time' => 'required|date',
            'end_time' => 'required|date|after:begin_time',
            'added_at' => 'nullable|date',
            'phone_number' => 'nullable|string|max:32',
            'access_group_id' => 'required|exists:access_groups,id',
            'door_right' => ['nullable', 'string', 'max:255', 'regex:/^[0-9]+(,[0-9]+)*$/'],
            'doors' => 'nullable|array',
            'doors.*' => 'integer|between:1,64',
            'terminal_ids' => 'nullable|array',
            'terminal_ids.*' => ['integer', Rule::exists('terminals', 'id')->where('integration', Terminal::INTEGRATION_HIKVISION_ISAPI)],
        ]);

        $isFullAdmin = $request->user()?->isFullAdmin() ?? true;
        if (! $isFullAdmin && $request->user()?->isHrOfficer()) {
            // HR Officer creates the personnel record without physical door permissions
            $doorRight = null;
            $rightPlan = null;
            $terminalIds = [];
        } else {
            $doors = isset($validated['door_right']) ? array_map('intval', explode(',', $validated['door_right'])) : ($validated['doors'] ?? [1]);
            if (array_filter($doors, fn ($door) => $door < 1 || $door > 64)) {
                throw ValidationException::withMessages(['door_right' => 'Device channels must be between 1 and 64.']);
            }
            $doorRight = implode(',', $doors);
            $rightPlan = array_map(fn ($d) => ['doorNo' => (int) $d, 'planTemplateNo' => '1'], $doors);
            $terminalIds = $request->input('terminal_ids', []);
        }

        $accessGroup = AccessGroup::findOrFail($validated['access_group_id']);

        $person = Personnel::create([
            'employee_no' => $validated['employee_no'],
            'name' => $validated['name'],
            'user_type' => $validated['user_type'],
            'valid_enabled' => (bool) $validated['valid_enabled'],
            'begin_time' => Carbon::parse($validated['begin_time'], config('attendance.timezone'))->utc(),
            'end_time' => Carbon::parse($validated['end_time'], config('attendance.timezone'))->utc(),
            'door_right' => $doorRight,
            'right_plan' => $rightPlan,
            'phone_number' => $validated['phone_number'] ?? null,
            'added_at' => isset($validated['added_at']) ? Carbon::parse($validated['added_at'], config('attendance.timezone'))->utc() : now(),
            'access_group_id' => $accessGroup->id,
            'department' => $accessGroup->name,
        ]);

        $this->audit()->log(
            action: 'personnel_created',
            outcome: AccessAuditLog::OUTCOME_INFO,
            description: "Registered personnel record for {$person->name} ({$person->employee_no}) in department {$person->department}.",
            context: ['employee_no' => $person->employee_no, 'department' => $person->department],
            actor: $request->user(),
            personnel: $person,
            ipAddress: DeviceActivityLogger::requestIp(),
        );

        if (! empty($terminalIds)) {
            $syncResult = $this->pendingSyncs->save($person, [], $terminalIds);
            $pushed = $syncResult['synced'];
            $pending = $syncResult['pending'];
        } else {
            $pushed = 0;
            $pending = 0;
        }

        $msg = "Person '{$person->name}' saved successfully.";
        if ($pushed > 0) {
            $msg .= " Synced to {$pushed} terminal(s).";
            $msg .= ' You can now enroll a fingerprint from this page.';
        } elseif ($pending > 0) {
            $msg .= " {$pending} device assignment(s) are pending confirmation — retry synchronization, then enroll a fingerprint.";
        } elseif ($isFullAdmin) {
            $msg .= ' No devices were selected. Use Edit Person to assign devices, then enroll a fingerprint here.';
        } else {
            $msg .= ' Personnel details registered. Physical door access permissions must be authorized by Systems Engineering.';
        }

        // Redirect to the person's profile so the admin can immediately enroll a fingerprint.
        return redirect()->route('personnel.show', $person)->with('success', $msg);
    }

    // =========================================================================
    // FINGERPRINT ENROLLMENT
    // =========================================================================

    /**
     * Legacy pre-save enrollment cannot create device access.
     */
    public function primeEnrollment(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Save the person and device assignments first, then enroll from their details page.'], 422);
    }

    public function enrollFingerprint(Request $request, Personnel $personnel): RedirectResponse
    {
        $request->validate([
            'terminal_id' => 'required|exists:terminals,id',
            'finger_id' => 'nullable|integer|min:1|max:10',
        ]);

        $terminalId = (int) $request->input('terminal_id');
        $fingerId = (int) $request->input('finger_id', 1);

        // Gate 1: the person must be active (valid, enabled, within dates, not suspended/blocklisted).
        if (! $personnel->isActive()) {
            return back()->with('error', 'This person is inactive, suspended, or blocked. Update their status in Edit Person before enrolling.');
        }

        // Gate 2: the terminal must be assigned with access_allowed = true.
        $terminal = Terminal::apiManaged()->find($terminalId);
        if (! $terminal) {
            return back()->with('error', 'The selected terminal no longer exists or is not API-managed.');
        }

        $assigned = $personnel->terminals()->whereKey($terminalId)->wherePivot('access_allowed', true)->exists();
        if (! $assigned) {
            return back()->with('error', 'This person is not assigned to that device. Open Edit Person, check the device, save, then retry enrollment.');
        }

        // Gate 3: the terminal must be online.
        if (! $terminal->isOnline()) {
            return back()->with('error', "Terminal '{$terminal->name}' is currently offline. Check device connectivity and retry.");
        }

        // Gate 4: the device must acknowledge the current profile before a fingerprint can be attached.
        try {
            $this->pendingSyncs->upsert($personnel, $terminal);
        } catch (\Throwable $error) {
            return back()->with('error', 'The device has not confirmed this person\'s profile yet. Retry synchronization from the access matrix, then try enrollment again. Details: '.$error->getMessage());
        }

        $result = $this->primeFingerprintOnTerminal($personnel, $terminalId, $fingerId);

        if ($result['success']) {
            return redirect()->back()->with('success',
                "Fingerprint slot #{$fingerId} is primed on '{$result['terminal_name']}' for {$personnel->name}. Ask them to place their finger on the reader now — the terminal will capture it automatically."
            );
        }

        return redirect()->back()->with('error', 'Fingerprint priming failed: '.$result['error']);
    }

    // =========================================================================
    // EXPORTS (CSV & Word)
    // =========================================================================

    public function export(): StreamedResponse
    {
        $personnel = Personnel::with('terminals')->orderBy('name')->get();

        return response()->streamDownload(function () use ($personnel) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee No', 'Name', 'Role', 'Status', 'Valid From', 'Valid Until', 'Door Channels', 'Phone', 'Assigned Devices (confirmation on person details)']);
            foreach ($personnel as $p) {
                fputcsv($out, [
                    $p->employee_no,
                    $p->name,
                    $p->roleLabel(),
                    $p->isActive() ? 'Active' : 'Inactive',
                    $p->begin_time?->format('Y-m-d H:i') ?? '',
                    $p->end_time?->format('Y-m-d H:i') ?? '',
                    $p->door_right ?? '',
                    $p->phone_number ?? '',
                    $p->terminals->where('pivot.access_allowed', true)->pluck('name')->implode(', '),
                ]);
            }
            fclose($out);
        }, 'zchpc access list.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportWord(): Response
    {
        $personnel = Personnel::with('terminals')->orderBy('name')->get();

        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>ZCHPC Access List</title>
            <style>
                body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; color: #333333; margin: 20px; }
                .header { border-bottom: 2px solid #004a86; padding-bottom: 10px; margin-bottom: 20px; }
                .title { font-size: 18pt; font-weight: bold; color: #004a86; margin: 0; }
                .subtitle { font-size: 13pt; color: #555555; margin: 4px 0 0; }
                .meta { font-size: 9pt; color: #777777; margin-top: 5px; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th { background-color: #004a86; color: #ffffff; text-align: left; padding: 8px 10px; font-size: 10pt; font-weight: bold; border: 1px solid #004a86; }
                td { padding: 7px 10px; border: 1px solid #dddddd; font-size: 10pt; vertical-align: middle; }
                tr:nth-child(even) { background-color: #f9fbfd; }
                .badge { font-weight: bold; }
                .active { color: #166534; }
                .inactive { color: #991b1b; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="title">Zimbabwe Centre for High Performance Computing</div>
                <div class="subtitle">Official Personnel Access List & Permissions Directory</div>
                <div class="meta">Exported on: '.now()->format('d M Y, H:i:s').' | Document: zchpc access list</div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 10%;">ID / Emp #</th>
                        <th style="width: 25%;">Full Name</th>
                        <th style="width: 15%;">Role</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 20%;">Validity Period</th>
                        <th style="width: 20%;">Assigned Devices (check confirmation in app)</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($personnel as $p) {
            $statusClass = $p->isActive() ? 'active' : 'inactive';
            $statusText = $p->isActive() ? 'Active' : 'Inactive';
            $terminalsText = $p->terminals->isNotEmpty()
                ? htmlspecialchars($p->terminals->where('pivot.access_allowed', true)->pluck('name')->implode(', '))
                : 'Local Only';

            $validity = ($p->begin_time ? $p->begin_time->format('d/m/Y') : '—').' to '.($p->end_time ? $p->end_time->format('d/m/Y') : '—');

            $html .= '<tr>
                <td style="font-family: Consolas, monospace; font-weight: bold;">'.htmlspecialchars($p->employee_no).'</td>
                <td><strong>'.htmlspecialchars($p->name).'</strong></td>
                <td>'.htmlspecialchars($p->roleLabel()).'</td>
                <td class="'.$statusClass.'"><strong>'.$statusText.'</strong></td>
                <td>'.$validity.'</td>
                <td>'.$terminalsText.'</td>
            </tr>';
        }

        $html .= '</tbody>
            </table>
        </body>
        </html>';

        return response($html, 200, [
            'Content-Type' => 'application/msword',
            'Content-Disposition' => 'attachment; filename="zchpc access list.doc"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Prime the fingerprint SetUp endpoint on one terminal.
     *
     * The SetUp request tells the terminal which employee number and slot to
     * expect next. The person then places their finger on the reader. Different
     * firmware generations return either FingerPrintStatus or ResponseStatus,
     * so both shapes are checked.
     */
    private function primeFingerprintOnTerminal(Personnel $person, int $terminalId, int $fingerId = 1): array
    {
        $terminal = Terminal::apiManaged()->find($terminalId);
        if (! $terminal) {
            return ['success' => false, 'error' => 'Terminal not found or not API-managed.'];
        }

        try {
            $client = $this->deviceManager->device($terminal->name);

            // Reader IDs from config so multi-reader units work; default to [1].
            $readerIds = array_values((array) config('access.enrollment.reader_ids', [1]));

            $result = $client->post('/ISAPI/AccessControl/FingerPrint/SetUp', [
                'FingerPrintCfg' => [
                    'employeeNo' => $person->employee_no,
                    'enableCardReader' => $readerIds,
                    'fingerPrintID' => $fingerId,
                    'fingerType' => 'normalFP',
                ],
            ]);

            // Firmware v3+: FingerPrintStatus.status
            $fpStatus = strtolower((string) ($result['FingerPrintStatus']['status'] ?? ''));
            if (in_array($fpStatus, ['success', 'ok'], true)) {
                return ['success' => true, 'terminal_name' => $terminal->name];
            }

            // Older firmware: ResponseStatus.statusCode 0 or 1 means accepted.
            $code = isset($result['ResponseStatus']['statusCode'])
                ? (int) $result['ResponseStatus']['statusCode']
                : null;
            if ($code !== null && in_array($code, [0, 1], true)) {
                return ['success' => true, 'terminal_name' => $terminal->name];
            }

            // Anything else is a device-side rejection.
            $deviceMsg = $result['ResponseStatus']['subStatusCode']
                ?? $result['ResponseStatus']['statusString']
                ?? ($fpStatus !== '' ? $fpStatus : null)
                ?? json_encode($result);

            return ['success' => false, 'error' => (string) $deviceMsg];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    public function show(Personnel $personnel): View
    {
        $personnel->load(['terminals', 'accessGroup', 'pendingSyncs']);
        $terminals = Terminal::apiManaged()->orderBy('phase')->get();
        $syncedIds = $personnel->terminals->filter(fn ($terminal) => (bool) $terminal->pivot->access_allowed)->pluck('id')->toArray();

        // Fetch fingerprint records from every synced terminal
        $fingerprintsByTerminal = $this->fetchFingerprints($personnel);

        return view('personnel.show', compact('personnel', 'terminals', 'syncedIds', 'fingerprintsByTerminal'));
    }

    // =========================================================================
    // EDIT / UPDATE
    // =========================================================================

    public function edit(Personnel $personnel): View
    {
        $personnel->load(['terminals', 'accessGroup', 'pendingSyncs']);
        $terminals = Terminal::apiManaged()->orderBy('phase')->get();
        $syncedIds = $personnel->terminals->filter(fn ($terminal) => (bool) $terminal->pivot->access_allowed)->pluck('id')->toArray();
        $accessGroups = AccessGroup::orderBy('name')->get();

        return view('personnel.edit', compact('personnel', 'terminals', 'syncedIds', 'accessGroups'));
    }

    public function update(Request $request, Personnel $personnel): RedirectResponse
    {
        $validated = $request->validate([
            'employee_no' => ['required', 'string', 'max:32', Rule::unique('personnel')->ignore($personnel->id)],
            'name' => 'required|string|max:64',
            'user_type' => 'required|in:normal,visitor,blocklist',
            'valid_enabled' => 'required|boolean',
            'begin_time' => 'required|date',
            'end_time' => 'required|date|after:begin_time',
            'added_at' => 'nullable|date',
            'phone_number' => 'nullable|string|max:32',
            'access_group_id' => 'required|exists:access_groups,id',
            'door_right' => ['nullable', 'string', 'max:255', 'regex:/^[0-9]+(,[0-9]+)*$/'],
            'doors' => 'nullable|array',
            'doors.*' => 'integer|between:1,64',
            'terminal_ids' => 'nullable|array',
            'terminal_ids.*' => ['integer', Rule::exists('terminals', 'id')->where('integration', Terminal::INTEGRATION_HIKVISION_ISAPI)],
        ]);

        $isFullAdmin = $request->user()?->isFullAdmin() ?? true;
        if (! $isFullAdmin && $request->user()?->isHrOfficer()) {
            // HR Officer preserves existing terminal and door permissions
            $doorRight = $personnel->door_right;
            $rightPlan = $personnel->right_plan;
            $terminalIds = $personnel->terminals()->wherePivot('access_allowed', true)->pluck('terminals.id')->all();
        } else {
            $doors = isset($validated['door_right']) ? array_map('intval', explode(',', $validated['door_right'])) : ($validated['doors'] ?? [1]);
            if (array_filter($doors, fn ($door) => $door < 1 || $door > 64)) {
                throw ValidationException::withMessages(['door_right' => 'Device channels must be between 1 and 64.']);
            }
            $doorRight = implode(',', $doors);
            $rightPlan = array_map(fn ($d) => ['doorNo' => (int) $d, 'planTemplateNo' => '1'], $doors);
            $terminalIds = $validated['terminal_ids'] ?? [];
        }

        $accessGroup = AccessGroup::findOrFail($validated['access_group_id']);

        $result = $this->pendingSyncs->save($personnel, [
            'employee_no' => $validated['employee_no'],
            'name' => $validated['name'],
            'user_type' => $validated['user_type'],
            'valid_enabled' => (bool) $validated['valid_enabled'],
            'begin_time' => Carbon::parse($validated['begin_time'], config('attendance.timezone'))->utc(),
            'end_time' => Carbon::parse($validated['end_time'], config('attendance.timezone'))->utc(),
            'door_right' => $doorRight,
            'right_plan' => $rightPlan,
            'phone_number' => $validated['phone_number'] ?? null,
            'added_at' => isset($validated['added_at']) ? Carbon::parse($validated['added_at'], config('attendance.timezone'))->utc() : ($personnel->added_at ?? now()),
            'access_group_id' => $accessGroup->id,
            'department' => $accessGroup->name,
        ], $terminalIds);

        $this->audit()->log(
            action: 'personnel_updated',
            outcome: AccessAuditLog::OUTCOME_INFO,
            description: "Updated personnel record for {$personnel->name} ({$personnel->employee_no}). Dispatched synchronization to assigned controllers.",
            context: ['employee_no' => $personnel->employee_no, 'terminals_count' => count($terminalIds)],
            actor: $request->user(),
            personnel: $personnel,
            ipAddress: DeviceActivityLogger::requestIp(),
        );

        $synced = $result['synced'];
        $pending = $result['pending'];

        if ($synced > 0 && $pending === 0) {
            $msg = "Person saved and all {$synced} device(s) confirmed. You can enroll a fingerprint below.";
        } elseif ($synced > 0) {
            $msg = "Person saved. {$synced} device(s) confirmed; {$pending} update(s) still pending — retry sync if enrollment is blocked.";
        } else {
            $msg = "Person saved. {$pending} device update(s) are pending confirmation — retry synchronization, then enroll a fingerprint.";
        }

        return redirect()->route('personnel.show', $personnel)->with('success', $msg);
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function destroy(Personnel $personnel): RedirectResponse
    {
        $employeeNo = $personnel->employee_no;
        $name = $personnel->name;

        try {
            $this->pendingSyncs->remove($personnel);
        } catch (\Throwable $error) {
            return back()->with('error', 'Local record retained. Device removal is not complete: '.$error->getMessage());
        }

        $this->audit()->log(
            action: 'personnel_deleted',
            outcome: AccessAuditLog::OUTCOME_WARNING,
            description: "Deactivated and removed personnel record for {$name} ({$employeeNo}).",
            context: ['employee_no' => $employeeNo],
            actor: request()->user(),
            ipAddress: DeviceActivityLogger::requestIp(),
            subjectLabel: $name,
        );

        return redirect()->route('personnel.index')->with('success', 'Person removed after device confirmation.');
    }

    // =========================================================================
    // IMPORT from device
    // =========================================================================

    /**
     * Import all users from a specific terminal into local DB.
     */
    public function import(Terminal $terminal): RedirectResponse
    {
        if (! $terminal->supports('personnel_sync')) {
            return back()->with('error', "{$terminal->name} does not provide the Hikvision personnel API.");
        }

        try {
            $client = $this->deviceManager->device($terminal->name);
            $service = new PersonService($client);
        } catch (\Throwable $e) {
            return back()->with('error', "Cannot connect to {$terminal->name}: ".$e->getMessage());
        }

        $imported = 0;
        $updated = 0;
        $errors = [];

        try {
            foreach ($service->all(pageSize: 30) as $person) {
                /** @var Person $person */
                try {
                    $existing = Personnel::where('employee_no', $person->employeeNo)->first();

                    $data = [
                        'name' => $person->name,
                        'user_type' => $person->userType->value === 'blocklist' ? 'normal' : $person->userType->value,
                        'valid_enabled' => true,
                        'begin_time' => $person->validEnabled && $person->beginTime ? Carbon::parse($person->beginTime, config('attendance.device_timezone'))->utc() : null,
                        'end_time' => $person->validEnabled && $person->endTime ? Carbon::parse($person->endTime, config('attendance.device_timezone'))->utc() : null,
                        'door_right' => $person->doorRight,
                        'right_plan' => $person->rightPlan ?: null,
                        'phone_number' => $person->phoneNumber,
                    ];

                    if ($existing) {
                        // Existing local edits and device restrictions remain authoritative.
                        $record = $existing;
                        $updated++;
                    } else {
                        $record = Personnel::create(array_merge(['employee_no' => $person->employeeNo], $data));
                        $imported++;
                    }

                    // Record sync relationship
                    if (! $record->terminals()->whereKey($terminal->id)->exists()) {
                        $record->terminals()->syncWithoutDetaching([
                            $terminal->id => ['synced_at' => now(), 'access_allowed' => $person->userType->value !== 'blocklist', 'sync_status' => 'imported'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            return back()->with('error', "Import failed from {$terminal->name}: ".$e->getMessage());
        }

        $msg = "Import from '{$terminal->name}' complete: {$imported} new, {$updated} existing profiles retained.";
        if (! empty($errors)) {
            $msg .= ' Errors: '.implode('; ', array_slice($errors, 0, 3));
        }

        return redirect()->route('personnel.index')->with('success', $msg);
    }

    /**
     * Import from ALL online terminals.
     */
    public function importAll(): RedirectResponse
    {
        $terminals = Terminal::apiManaged()->get();
        $results = [];

        foreach ($terminals as $terminal) {
            try {
                $client = $this->deviceManager->device($terminal->name);
                $service = new PersonService($client);
                $count = 0;

                foreach ($service->all(pageSize: 30) as $person) {
                    $existing = Personnel::where('employee_no', $person->employeeNo)->first();
                    $data = [
                        'name' => $person->name,
                        'user_type' => $person->userType->value === 'blocklist' ? 'normal' : $person->userType->value,
                        'valid_enabled' => true,
                        'begin_time' => $person->validEnabled && $person->beginTime ? Carbon::parse($person->beginTime, config('attendance.device_timezone'))->utc() : null,
                        'end_time' => $person->validEnabled && $person->endTime ? Carbon::parse($person->endTime, config('attendance.device_timezone'))->utc() : null,
                        'door_right' => $person->doorRight,
                        'right_plan' => $person->rightPlan ?: null,
                        'phone_number' => $person->phoneNumber,
                    ];

                    if ($existing) {
                        // Existing local edits and device restrictions remain authoritative.
                        $record = $existing;
                    } else {
                        $record = Personnel::create(array_merge(['employee_no' => $person->employeeNo], $data));
                    }

                    if (! $record->terminals()->whereKey($terminal->id)->exists()) {
                        $record->terminals()->syncWithoutDetaching([
                            $terminal->id => ['synced_at' => now(), 'access_allowed' => $person->userType->value !== 'blocklist', 'sync_status' => 'imported'],
                        ]);
                    }
                    $count++;
                }
                $results[] = "{$terminal->name}: {$count} records";
            } catch (\Throwable $e) {
                $results[] = "{$terminal->name}: OFFLINE";
            }
        }

        return redirect()->route('personnel.index')
            ->with('success', 'Import complete. '.implode(', ', $results));
    }

    // =========================================================================
    // SYNC — push local record(s) to terminals
    // =========================================================================

    /**
     * Sync a single person to all selected terminals.
     */
    public function sync(Request $request, Personnel $personnel): RedirectResponse
    {
        $result = $this->pendingSyncs->retry($personnel);

        return back()->with('success', "Existing assignments refreshed: {$result['synced']} device(s) confirmed; {$result['pending']} pending.");
    }

    public function syncAll(): RedirectResponse
    {
        $confirmed = 0;
        $pending = 0;
        foreach (Personnel::all() as $person) {
            $result = $this->pendingSyncs->retry($person);
            $confirmed += $result['synced'];
            $pending += $result['pending'];
        }

        return back()->with('success', "Existing assignments refreshed: {$confirmed} confirmed; {$pending} pending. No new device access was granted.");
    }

    // =========================================================================
    // HELPERS — push records to terminals, fingerprint, DTO
    // =========================================================================

    /**
     * Query every synced terminal and collect fingerprint slot records for
     * the given person. Returns a keyed array:
     *
     *   [ 'terminalName' => ['online' => bool, 'count' => int, 'slots' => [...]] ]
     *
     * Each slot array contains: fingerPrintID, fingerType, leaderFPStatus.
     */
    private function fetchFingerprints(Personnel $personnel): array
    {
        $result = [];

        foreach ($personnel->terminals->filter->isApiManaged() as $terminal) {
            $isOnline = Cache::get("terminal.{$terminal->id}.online", false);

            if (! $isOnline) {
                $result[$terminal->name] = [
                    'online' => false,
                    'count' => 0,
                    'slots' => [],
                ];

                continue;
            }

            try {
                $client = $this->deviceManager->device($terminal->name);
                $service = new FingerprintService($client);

                $raw = $service->search(employeeNo: $personnel->employee_no);

                // The ISAPI response nests under FingerPrintSearch → MatchList or FingerPrintInfo → FingerPrintList
                $list = $raw['FingerPrintSearch']['MatchList'] ?? $raw['FingerPrintInfo']['FingerPrintList'] ?? $raw['data'] ?? [];
                if (! empty($list) && ! array_is_list($list)) {
                    $list = [$list];
                }

                $slots = [];
                foreach ($list as $fp) {
                    $slots[] = [
                        'id' => (int) ($fp['fingerPrintID'] ?? $fp['FingerPrintID'] ?? 0),
                        'type' => $fp['fingerType'] ?? $fp['FingerType'] ?? 'unknown',
                        'leader' => (bool) ($fp['leaderFPStatus'] ?? $fp['LeaderFPStatus'] ?? false),
                    ];
                }

                // Sort by slot id
                usort($slots, fn ($a, $b) => $a['id'] <=> $b['id']);

                $result[$terminal->name] = [
                    'online' => true,
                    'count' => count($slots),
                    'slots' => $slots,
                ];
            } catch (\Throwable) {
                $result[$terminal->name] = [
                    'online' => false,
                    'count' => 0,
                    'slots' => [],
                ];
            }
        }

        return $result;
    }
}
