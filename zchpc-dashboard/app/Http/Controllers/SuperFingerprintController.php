<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\TerminalSuperFingerprint;
use App\Services\PendingTerminalSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;

class SuperFingerprintController extends Controller
{
    public function __construct(private readonly DeviceManager $deviceManager) {}

    public function index(): View
    {
        return view('super-fingerprints.index', [
            'assignments' => TerminalSuperFingerprint::with(['terminal', 'personnel'])
                ->latest('updated_at')
                ->get(),
            'terminals' => Terminal::apiManaged()->orderBy('phase')->orderBy('name')->get(),
            'personnel' => Personnel::orderBy('name')->get(['id', 'employee_no', 'name']),
        ]);
    }

    /**
     * Return only metadata for fingerprints that actually exist for this person
     * on this terminal. Fingerprint templates are deliberately never returned
     * to the browser or stored in this application.
     */
    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'personnel_id' => ['required', 'integer', 'exists:personnel,id'],
        ]);

        $terminal = Terminal::findOrFail($validated['terminal_id']);
        $personnel = Personnel::findOrFail($validated['personnel_id']);

        if (! $terminal->supports('super_fingerprint')) {
            return response()->json(['message' => 'The selected terminal does not provide the Hikvision super-fingerprint API.'], 422);
        }

        try {
            $client = $this->deviceManager->device($terminal->name);
            $response = $client->post('/ISAPI/AccessControl/FingerPrintUpload', [
                'FingerPrintCond' => [
                    'searchID' => bin2hex(random_bytes(8)),
                    'employeeNo' => $personnel->employee_no,
                    'cardReaderNo' => 1,
                ],
            ]);

            $list = $response['FingerPrintInfo']['FingerPrintList'] ?? [];
            $fingerprints = is_array($list) && $list !== []
                ? (array_is_list($list) ? $list : [$list])
                : [];

            return response()->json([
                'fingerprints' => collect($fingerprints)
                    ->filter(static fn (mixed $fingerprint): bool => is_array($fingerprint))
                    ->filter(static fn (array $fingerprint): bool => isset($fingerprint['fingerPrintID']))
                    ->map(fn (array $fingerprint): array => [
                        'id' => (int) $fingerprint['fingerPrintID'],
                        'reader' => (int) ($fingerprint['cardReaderNo'] ?? 1),
                        'type' => (string) ($fingerprint['fingerType'] ?? 'normalFP'),
                        'label' => $this->fingerprintLabel((int) $fingerprint['fingerPrintID'], (string) ($fingerprint['fingerType'] ?? 'normalFP'), (int) ($fingerprint['cardReaderNo'] ?? 1)),
                    ])
                    ->values(),
            ]);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'fingerprintnotlinkemployeeidorcard')
                || str_contains($msg, 'nofp')
                || str_contains($msg, 'nofingerprint')) {
                return response()->json(['fingerprints' => []]);
            }

            return response()->json([
                'message' => $this->terminalError($e),
            ], 422);
        }
    }

    /**
     * Promote an already-enrolled terminal fingerprint to a super fingerprint.
     * The biometric template is read from, and written straight back to, the
     * same terminal; it is not kept in this application or its database.
     */
    public function enroll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'personnel_id' => ['required', 'integer', 'exists:personnel,id'],
            'finger_id' => ['required', 'integer', 'between:1,10'],
        ]);

        $terminal = Terminal::findOrFail($validated['terminal_id']);
        $personnel = Personnel::findOrFail($validated['personnel_id']);
        $fingerId = (int) $validated['finger_id'];

        if (! $personnel->isActive() || ! $personnel->terminals()->whereKey($terminal->id)->wherePivot('access_allowed', true)->exists()) {
            return back()->with('error', 'Assign active access to this device before promoting a fingerprint.');
        }

        if (! $terminal->supports('super_fingerprint')) {
            return back()->withInput()->with('error', "{$terminal->name} does not provide the Hikvision super-fingerprint API.");
        }

        try {
            $client = $this->deviceManager->device($terminal->name);
            app(PendingTerminalSyncService::class)->upsert($personnel, $terminal);

            $fingerprint = $this->fingerprintOnTerminal($client, $personnel->employee_no, $fingerId);
            $fingerData = $fingerprint['fingerData'] ?? null;
            if (! is_string($fingerData) || $fingerData === '') {
                throw new \RuntimeException(
                    "No fingerprint template was returned for {$personnel->name} in slot #{$fingerId}. "
                    .'Enroll that fingerprint on the terminal first, then promote it here.'
                );
            }

            $payload = [
                'FingerPrintCfg' => [
                    'employeeNo' => $personnel->employee_no,
                    'enableCardReader' => [(int) ($fingerprint['cardReaderNo'] ?? 1)],
                    'fingerPrintID' => $fingerId,
                    'fingerType' => 'superFP',
                    'fingerData' => $fingerData,
                    'checkEmployeeNo' => true,
                ],
            ];

            // Preserve any existing first-person authorization permissions.
            if (isset($fingerprint['leaderFP']) && is_array($fingerprint['leaderFP'])) {
                $payload['FingerPrintCfg']['leaderFP'] = $fingerprint['leaderFP'];
            }

            $result = $client->post('/ISAPI/AccessControl/FingerPrint/SetUp', $payload);

            TerminalSuperFingerprint::updateOrCreate(
                [
                    'terminal_id' => $terminal->id,
                    'personnel_id' => $personnel->id,
                    'finger_id' => $fingerId,
                ],
                [
                    'status' => $this->terminalAcceptedFingerprint($result) ? 'active' : 'submitted',
                    'last_response' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            );
        } catch (\Throwable $e) {
            TerminalSuperFingerprint::updateOrCreate(
                [
                    'terminal_id' => $terminal->id,
                    'personnel_id' => $personnel->id,
                    'finger_id' => $fingerId,
                ],
                [
                    'status' => 'failed',
                    'last_response' => $e->getMessage(),
                ],
            );

            return back()->withInput()->with('error', "Super fingerprint setup on {$terminal->name} failed: {$e->getMessage()}");
        }

        return redirect()->route('super-fingerprints.index')->with(
            'success',
            "{$personnel->name}'s fingerprint in slot #{$fingerId} was promoted to a super fingerprint on {$terminal->name}. The terminal response has been recorded.",
        );
    }

    /** @return array<string, mixed> */
    private function fingerprintOnTerminal(HikvisionClient $client, string $employeeNo, int $fingerId): array
    {
        $response = $client->post('/ISAPI/AccessControl/FingerPrintUpload', [
            'FingerPrintCond' => [
                'searchID' => bin2hex(random_bytes(8)),
                'employeeNo' => $employeeNo,
                'cardReaderNo' => 1,
                'fingerPrintID' => $fingerId,
            ],
        ]);

        $info = $response['FingerPrintInfo'] ?? [];
        $list = $info['FingerPrintList'] ?? [];
        if (! is_array($list) || $list === []) {
            throw new \RuntimeException("No enrolled fingerprint was found in slot #{$fingerId} on the terminal.");
        }

        $fingerprints = array_is_list($list) ? $list : [$list];
        foreach ($fingerprints as $fingerprint) {
            if (is_array($fingerprint) && (int) ($fingerprint['fingerPrintID'] ?? 0) === $fingerId) {
                return $fingerprint;
            }
        }

        throw new \RuntimeException("No enrolled fingerprint was found in slot #{$fingerId} on the terminal.");
    }

    /** @param array<string, mixed> $result */
    private function terminalAcceptedFingerprint(array $result): bool
    {
        $status = strtolower((string) ($result['FingerPrintStatus']['status'] ?? $result['statusString'] ?? ''));
        if (in_array($status, ['success', 'ok'], true) || (int) ($result['statusCode'] ?? 0) === 1) {
            return true;
        }

        $statuses = $result['FingerPrintStatus']['StatusList'] ?? [];
        if (! is_array($statuses) || $statuses === []) {
            return false;
        }

        $statuses = array_is_list($statuses) ? $statuses : [$statuses];

        return collect($statuses)->every(
            static fn (mixed $item): bool => is_array($item)
                && in_array((string) ($item['cardReaderRecvStatus'] ?? ''), ['1', '5', '8'], true),
        );
    }

    private function fingerprintLabel(int $fingerId, string $type, int $reader): string
    {
        $typeLabel = match ($type) {
            'superFP' => 'Super fingerprint',
            'hijackFP' => 'Duress fingerprint',
            'patrolFP' => 'Patrol fingerprint',
            'dismissingFP' => 'Dismissing fingerprint',
            default => 'Normal fingerprint',
        };

        return "Fingerprint ID #{$fingerId} - {$typeLabel} (Reader {$reader})";
    }

    private function terminalError(\Throwable $error): string
    {
        $message = $error->getMessage();

        if (str_contains($message, 'cURL error 67')) {
            return 'The terminal rejected its saved credentials. Update the terminal username and password, then try again.';
        }

        if (str_contains($message, 'cURL error 7') || str_contains($message, 'cURL error 28') || str_contains($message, 'timed out') || str_contains($message, 'Couldn\'t connect')) {
            return 'The selected terminal is offline or unreachable (connection timed out).';
        }

        return $message;
    }
}
