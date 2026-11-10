<?php

namespace App\Http\Controllers;

use App\Models\AccessGroup;
use App\Models\Door;
use App\Models\FingerprintEnrollment;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Services\AccessAuditLogger;
use App\Services\AccessWizard;
use App\Services\DeviceActivityLogger;
use App\Services\DoorAccessService;
use App\Services\DoorCatalog;
use App\Services\FingerprintEnrollmentService;
use App\Services\PersonnelProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccessWizardController extends Controller
{
    public function __construct(
        private readonly AccessWizard $wizard,
        private readonly PersonnelProfileService $profiles,
        private readonly FingerprintEnrollmentService $enrollments,
        private readonly DoorAccessService $access,
        private readonly DoorCatalog $catalog,
        private readonly AccessAuditLogger $audit,
    ) {}

    public function details(): View|RedirectResponse
    {
        Gate::authorize('access.manage');

        if ($this->wizard->person() !== null) {
            return redirect()->route('access.wizard.device');
        }

        return view('access.wizard.details', [
            'personnel' => null,
            'departments' => AccessGroup::orderBy('name')->get(),
        ]);
    }

    public function storeDetails(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $data = $request->validate($this->profiles->rules($this->wizard->person()));

        $duplicates = $this->profiles->duplicates($data, $this->wizard->personId());
        if ($duplicates->isNotEmpty() && ! (bool) ($data['confirm_duplicate'] ?? false)) {
            return back()->withInput()->with('duplicate_matches', $duplicates)
                ->withErrors(['confirm_duplicate' => 'Possible duplicate people were found. Review the list, then confirm this is a different person.']);
        }

        try {
            $person = $this->wizard->person();

            if ($person === null) {
                $person = $this->profiles->createDraft($data, $request->file('photo'),
                    $request->user(), DeviceActivityLogger::requestIp());
                $this->wizard->setPerson($person);
            } else {
                $this->profiles->updateProfile($person, $data, $request->file('photo'),
                    $request->user(), DeviceActivityLogger::requestIp());
            }
        } catch (\Throwable $error) {
            return back()->withInput()->with('error', $error->getMessage());
        }

        return redirect()->route('access.wizard.device');
    }

    public function device(): View|RedirectResponse
    {
        Gate::authorize('access.manage');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create')
                ->with('error', 'Start with the person details, or resume a draft from Users & Access.');
        }

        $terminals = Terminal::apiManaged()->orderBy('phase')->orderBy('name')->get();

        return view('access.wizard.device', [
            'personnel' => $person,
            'terminals' => $terminals,
            'selectedTerminalId' => $this->wizard->terminalId(),
            'busyTerminalIds' => FingerprintEnrollment::query()->active()
                ->whereNotNull('terminal_id')->distinct()->pluck('terminal_id')->all(),
        ]);
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $validated = $request->validate([
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
        ]);

        $terminal = Terminal::apiManaged()->findOrFail($validated['terminal_id']);
        $this->wizard->setTerminal($terminal);

        return redirect()->route('access.wizard.capture');
    }

    public function capture(): View|RedirectResponse
    {
        Gate::authorize('access.enroll');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $terminal = $this->wizard->terminal();
        if ($terminal === null) {
            return redirect()->route('access.wizard.device');
        }

        if ($terminal->status === Terminal::STATUS_ONLINE && ! $terminal->isOnline()) {
            try {
                app(\App\Services\DeviceHealthService::class)->check($terminal, 'enrollment');
                $terminal->refresh();
            } catch (\Throwable) {
            }
        }

        $enrollments = $this->wizard->enrollments();
        $active = $enrollments->first(fn (FingerprintEnrollment $enrollment): bool => $enrollment->isActive());

        return view('access.wizard.capture', [
            'personnel' => $person,
            'terminal' => $terminal,
            'enrollments' => $enrollments,
            'activeEnrollment' => $active,
            'fingerIds' => (array) config('access.enrollment.finger_ids', range(1, 10)),
        ]);
    }

    public function startEnrollment(Request $request): RedirectResponse
    {
        Gate::authorize('access.enroll');

        $person = $this->wizard->person();
        $terminal = $this->wizard->terminal();

        if ($person === null || $terminal === null) {
            return redirect()->route('access.wizard.device');
        }

        $validated = $request->validate([
            'finger_id' => ['required', 'integer', 'between:1,10'],
        ]);

        try {
            $enrollment = $this->enrollments->start($person, $terminal, (int) $validated['finger_id'],
                $request->user(), DeviceActivityLogger::requestIp());
            $this->wizard->rememberEnrollment($enrollment->id);
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return redirect()->route('access.wizard.capture')
            ->with('success', 'Enrollment requested. Place a finger on '.$terminal->name.' now.');
    }

    public function cancelEnrollment(Request $request, FingerprintEnrollment $enrollment): RedirectResponse
    {
        Gate::authorize('access.enroll');

        $person = $this->wizard->person();
        abort_unless($person !== null && (int) $enrollment->personnel_id === (int) $person->id, 404);

        try {
            $this->enrollments->cancel($enrollment, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return redirect()->route('access.wizard.capture')->with('success', 'Enrollment cancelled.');
    }

    public function retryEnrollment(Request $request, FingerprintEnrollment $enrollment): RedirectResponse
    {
        Gate::authorize('access.enroll');

        $person = $this->wizard->person();
        abort_unless($person !== null && (int) $enrollment->personnel_id === (int) $person->id, 404);

        if ($enrollment->terminal === null) {
            return back()->with('error', 'That terminal no longer exists.');
        }

        try {
            $retry = $this->enrollments->start($person, $enrollment->terminal, (int) $enrollment->finger_id,
                $request->user(), DeviceActivityLogger::requestIp());
            $this->wizard->rememberEnrollment($retry->id);
        } catch (\Throwable $error) {
            return back()->with('error', $error->getMessage());
        }

        return redirect()->route('access.wizard.capture')->with('success', 'Retry requested.');
    }

    public function skipEnrollment(Request $request): RedirectResponse
    {
        Gate::authorize('access.enroll');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $this->wizard->skipEnrollment();

        $this->audit->log('enrollment_skipped', 'warning',
            'Fingerprint enrollment was deferred for '.$person->name.'. The person can be enrolled locally and imported later.',
            [], $request->user(), $person, terminal: $this->wizard->terminal(),
            ipAddress: DeviceActivityLogger::requestIp());

        return redirect()->route('access.wizard.doors')
            ->with('success', 'Continuing without a fingerprint. You can enroll this person later from their profile.');
    }

    public function doors(): View|RedirectResponse
    {
        Gate::authorize('access.manage');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $hasConfirmed = $this->wizard->enrollments()
            ->contains('status', FingerprintEnrollment::STATUS_CONFIRMED);

        if (! $hasConfirmed && ! $this->wizard->enrollmentSkipped()) {
            return redirect()->route('access.wizard.capture')
                ->with('error', 'Confirm a fingerprint, or choose to continue without one, before selecting doors.');
        }

        return view('access.wizard.doors', [
            'personnel' => $person,
            'doors' => $this->catalog->groupedDoors(),
            'accessGroups' => AccessGroup::query()->with('doors')->has('doors')->orderBy('name')->get(),
            'selectedDoorIds' => $this->wizard->doorIds(),
        ]);
    }

    public function saveDoors(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $validated = $request->validate([
            'door_ids' => ['nullable', 'array', 'max:100'],
            'door_ids.*' => ['integer', 'distinct', 'exists:doors,id'],
        ]);

        $ids = array_map('intval', $validated['door_ids'] ?? []);
        $doors = Door::query()->whereIn('id', $ids)->get();

        foreach ($doors as $door) {
            if (! $door->is_active || ! $door->isDeployable()) {
                return back()->withInput()->withErrors([
                    'door_ids' => $door->name.' is out of service or has no controller, so it cannot be granted.',
                ]);
            }
        }

        $this->wizard->setDoorIds($ids);

        return redirect()->route('access.wizard.review');
    }

    public function review(): View|RedirectResponse
    {
        Gate::authorize('access.manage');

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $doors = Door::query()->with('terminal')
            ->whereIn('id', $this->wizard->doorIds())
            ->orderBy('building')->orderBy('floor')->orderBy('name')
            ->get();

        return view('access.wizard.review', [
            'personnel' => $person,
            'doors' => $doors,
            'byController' => $doors->groupBy(fn (Door $door): string => $door->controllerLabel()),
            'enrollments' => $this->wizard->enrollments(),
            'accessGroups' => AccessGroup::query()->with('doors')->orderBy('name')->get(),
        ]);
    }

    public function deploy(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $request->validate(['confirm' => ['accepted']]);

        $person = $this->wizard->person();
        if ($person === null) {
            return redirect()->route('access.users.create');
        }

        $hasConfirmedFingerprint = $this->wizard->enrollments()
            ->contains('status', FingerprintEnrollment::STATUS_CONFIRMED);

        if (! $hasConfirmedFingerprint && ! $this->wizard->enrollmentSkipped()) {
            return redirect()->route('access.wizard.capture')
                ->with('error', 'Confirm a fingerprint, or explicitly continue without one, before saving this person.');
        }

        $doors = Door::query()->whereIn('id', $this->wizard->doorIds())->get();

        $person->forceFill([
            'status' => Personnel::STATUS_ACTIVE,
            'valid_enabled' => true,
        ])->save();

        $result = ['terminals' => [], 'synced' => 0, 'pending' => 0];

        try {
            if ($doors->isNotEmpty()) {
                $result = $this->access->grant($person, $doors->pluck('id')->all(), $request->user(),
                    null, null, DeviceActivityLogger::requestIp());
            }
        } catch (\Throwable $error) {
            $person->forceFill(['status' => Personnel::STATUS_DRAFT])->save();

            return back()->with('error', 'Door access was not applied, so the person remains a draft: '.$error->getMessage());
        }

        $this->audit->log('user_onboarded', 'success',
            $person->name.' completed onboarding with '.$doors->count().' door(s).',
            [
                'doors' => $doors->pluck('name')->all(),
                'confirmed_controllers' => $result['synced'],
                'pending_controllers' => $result['pending'],
            ],
            $request->user(), $person, ipAddress: DeviceActivityLogger::requestIp());

        $this->wizard->complete();

        $message = $doors->isEmpty()
            ? $person->name.' was created with no door access, following least privilege.'
            : $person->name.' was created. '.$result['synced'].' of '.count($result['terminals']).' controller(s) confirmed the new permissions.';

        return redirect()->route('access.users.deployed', $person)->with('success', $message);
    }

    public function deployed(Personnel $personnel): View|RedirectResponse
    {
        Gate::authorize('access.view');

        if ($personnel->isDraft()) {
            return redirect()->route('access.users.show', $personnel)
                ->with('error', 'That person has not completed onboarding yet.');
        }

        $personnel->load(['terminals', 'doors', 'fingerprintEnrollments', 'pendingSyncs']);

        return view('access.deployed', [
            'personnel' => $personnel,
            'controllerStates' => $this->access->controllerStates($personnel),
            'enrollments' => $personnel->fingerprintEnrollments()->latest('id')->get(),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $person = $this->wizard->person();

        if ($person === null) {
            $this->wizard->forget();

            return redirect()->route('access.index')->with('success', 'Setup cleared.');
        }

        try {
            $this->access->discardDraft($person, $request->user(), DeviceActivityLogger::requestIp());
        } catch (\Throwable $error) {
            return back()->with('error', 'Draft retained: '.$error->getMessage());
        }

        $this->wizard->forget();

        return redirect()->route('access.index')->with('success', 'Setup cancelled and the draft was removed.');
    }

    /** Resume a draft that is already in progress. */
    public function resume(Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        if (! $personnel->isDraft()) {
            return redirect()->route('access.users.show', $personnel);
        }

        $this->wizard->setPerson($personnel);

        return redirect()->route('access.wizard.device');
    }
}
