<?php

namespace App\Http\Controllers;

use App\Models\Door;
use App\Models\PersonnelDoor;
use App\Models\Terminal;
use App\Services\AccessAuditLogger;
use App\Services\AccessProvisioner;
use App\Services\DeviceActivityLogger;
use App\Services\DoorCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DoorController extends Controller
{
    public function __construct(
        private readonly DoorCatalog $catalog,
        private readonly AccessAuditLogger $audit,
        private readonly AccessProvisioner $provisioner,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('access.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'building' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $query = Door::query()->with('terminal')
            ->withCount(['grants as granted_people_count']);

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')
                    ->orWhere('building', 'like', '%'.$search.'%')
                    ->orWhere('zone', 'like', '%'.$search.'%');
            });
        }

        if (! empty($validated['building'])) {
            $query->where('building', $validated['building']);
        }

        if (($validated['status'] ?? '') === 'active') {
            $query->where('is_active', true);
        } elseif (($validated['status'] ?? '') === 'inactive') {
            $query->where('is_active', false);
        }

        $doors = $query->orderBy('building')->orderBy('floor')->orderBy('sort_order')->orderBy('name')
            ->paginate(30)->withQueryString();

        return view('access.doors', [
            'doors' => $doors,
            'byBuilding' => $doors->getCollection()->groupBy(fn (Door $door): string => $door->building ?: 'Unassigned building'),
            'buildings' => Door::query()->whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
            'terminals' => Terminal::apiManaged()->withCount('doors')->orderBy('phase')->orderBy('name')->get(),
            'unregisteredControllers' => Terminal::apiManaged()->whereDoesntHave('doors')->orderBy('name')->get(),
            'filters' => $validated,
            'stats' => [
                'doors' => Door::query()->count(),
                'active' => Door::query()->active()->count(),
                'grants' => PersonnelDoor::query()->count(),
                'unregistered' => Terminal::apiManaged()->whereDoesntHave('doors')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $validated = $this->validateDoor($request);
        $data = array_merge([
            'code' => null, 'building' => null, 'floor' => null, 'zone' => null,
            'terminal_id' => null, 'notes' => null, 'sort_order' => 0,
        ], $validated);

        $door = Door::query()->create([
            ...$data,
            'code' => $data['code'] ?: 'D-'.Str::upper(Str::random(6)),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('door_created', 'success', 'Registered door '.$door->name.' on '.$door->controllerLabel().'.',
            ['door' => $door->name, 'channel' => $door->door_no], door: $door,
            ipAddress: DeviceActivityLogger::requestIp());

        return redirect()->route('access.doors.index')->with('success', 'Door registered.');
    }

    public function update(Request $request, Door $door): RedirectResponse
    {
        Gate::authorize('access.manage');

        $validated = $this->validateDoor($request, $door);
        $active = $request->boolean('is_active');
        $channelChanged = (int) $door->terminal_id !== (int) ($validated['terminal_id'] ?? 0)
            || (int) $door->door_no !== (int) $validated['door_no'];

        if (($channelChanged || ($door->is_active && ! $active)) && $door->grants()->exists()) {
            throw ValidationException::withMessages([
                'door_no' => 'This door has granted people. Revoke those grants before changing its controller, channel or service state.',
            ]);
        }

        $door->update([...$validated, 'is_active' => $active]);

        $this->audit->log('door_updated', 'info', 'Door '.$door->name.' was updated.',
            ['channel' => $door->door_no, 'active' => $door->is_active], door: $door,
            ipAddress: DeviceActivityLogger::requestIp());

        return redirect()->route('access.doors.index')->with('success', 'Door updated.');
    }

    public function destroy(Door $door): RedirectResponse
    {
        Gate::authorize('access.manage');

        if ($door->grants()->exists()) {
            return back()->with('error', 'Revoke every grant for this door before removing it, so no controller keeps an unexpected permission.');
        }

        $name = $door->name;
        $door->delete();

        $this->audit->log('door_removed', 'info', 'Door '.$name.' was removed from the registry.',
            ['door' => $name], ipAddress: DeviceActivityLogger::requestIp());

        return redirect()->route('access.doors.index')->with('success', 'Door removed.');
    }

    public function provision(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $created = $this->catalog->registerMissingControllerDoors();

        if ($created === []) {
            return back()->with('success', 'Every API-managed controller already has a registered door.');
        }

        foreach ($created as $door) {
            $this->audit->log('door_created', 'info', 'Registered '.$door->name.' from its controller.',
                [], $request->user(), door: $door);
        }

        return back()->with('success', count($created).' controller door(s) registered. No access was granted.');
    }

    public function probe(Request $request, Terminal $terminal): RedirectResponse
    {
        Gate::authorize('access.manage');

        if (! $terminal->isApiManaged()) {
            return back()->with('error', $terminal->name.' has no remote API to query.');
        }

        $result = $this->provisioner->probeFingerprintCapability($terminal, DeviceActivityLogger::requestIp());

        $message = match ($result) {
            Terminal::FP_SUPPORTED => $terminal->name.' confirmed the fingerprint capture API.',
            Terminal::FP_UNSUPPORTED => $terminal->name.' does not expose a fingerprint capture API; local enrollment and import is the supported route.',
            default => 'The capability of '.$terminal->name.' could not be determined. Check the device and try again.',
        };

        return back()->with($result === Terminal::FP_SUPPORTED ? 'success' : 'error', $message);
    }

    /** @return array<string, mixed> */
    private function validateDoor(Request $request, ?Door $door = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('doors', 'code')->ignore($door?->id)],
            'building' => ['nullable', 'string', 'max:80'],
            'floor' => ['nullable', 'string', 'max:40'],
            'zone' => ['nullable', 'string', 'max:80'],
            'terminal_id' => ['nullable', 'integer', 'exists:terminals,id'],
            'door_no' => ['required', 'integer', 'between:1,64'],
            'plan_template_no' => ['required', 'string', 'max:16', 'regex:/^[0-9]+$/'],
            'notes' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
        ]);

        $validated['terminal_id'] = $validated['terminal_id'] ?? null;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        if ($validated['terminal_id'] !== null) {
            $terminal = Terminal::findOrFail($validated['terminal_id']);
            if (! $terminal->isApiManaged()) {
                throw ValidationException::withMessages(['terminal_id' => 'That device has no remote API, so it cannot enforce a door.']);
            }

            $duplicate = Door::query()
                ->where('terminal_id', $terminal->id)
                ->where('door_no', $validated['door_no'])
                ->when($door !== null, fn ($query) => $query->whereKeyNot($door->id))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'door_no' => 'That controller channel is already registered as another door.',
                ]);
            }
        }

        return $validated;
    }
}
