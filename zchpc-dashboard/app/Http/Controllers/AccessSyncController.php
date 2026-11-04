<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAccessSyncJob;
use App\Models\DeviceSyncEvent;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Services\DoorAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccessSyncController extends Controller
{
    public function __construct(private readonly DoorAccessService $access) {}

    public function index(): View
    {
        Gate::authorize('access.view');

        $pending = PendingTerminalSync::query()
            ->with(['personnel', 'terminal'])
            ->orderBy('next_attempt_at')
            ->limit(150)
            ->get();

        return view('access.sync', [
            'pending' => $pending,
            'pendingByTerminal' => $pending->groupBy(fn (PendingTerminalSync $sync): string => $sync->terminal?->name ?? 'Removed controller'),
            'stats' => [
                'pending' => PendingTerminalSync::query()->count(),
                'failing' => PendingTerminalSync::query()->where('attempts', '>', 0)->count(),
                'confirmed_today' => DeviceSyncEvent::query()
                    ->where('status', DeviceSyncEvent::STATUS_SUCCESS)
                    ->where('created_at', '>=', now()->startOfDay())->count(),
                'failed_today' => DeviceSyncEvent::query()
                    ->where('status', DeviceSyncEvent::STATUS_FAILED)
                    ->where('created_at', '>=', now()->startOfDay())->count(),
                'no_access' => Personnel::query()->onboarded()->whereDoesntHave('doors')->count(),
            ],
            'terminals' => Terminal::apiManaged()->orderBy('name')->get(),
            'people' => Personnel::query()->onboarded()->orderBy('name')->get(['id', 'name', 'employee_no']),
            'events' => DeviceSyncEvent::query()->with('terminal')->latest('id')->limit(30)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('access.manage');

        $validated = $request->validate([
            'scope' => ['required', 'in:all,selected,pending'],
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:personnel,id'],
        ]);

        if ($validated['scope'] === 'selected' && empty($validated['ids'])) {
            throw ValidationException::withMessages(['ids' => 'Select at least one person to synchronize.']);
        }

        if ($validated['scope'] === 'pending') {
            $count = PendingTerminalSync::query()->count();

            if ($count === 0) {
                return back()->with('success', 'There are no pending controller writes.');
            }

            PendingTerminalSync::query()->update(['next_attempt_at' => now()]);
            ProcessAccessSyncJob::dispatch();

            return back()->with('success', 'Retrying '.$count.' pending controller write(s). The queue worker will confirm each one.');
        }

        $ids = $validated['scope'] === 'all'
            ? Personnel::query()->onboarded()->pluck('id')->all()
            : array_map('intval', $validated['ids'] ?? []);

        $queued = $this->access->queueBatch($ids);

        if ($queued === 0) {
            return back()->with('success', 'Nothing to synchronize: those people have no controller assignments or door grants.');
        }

        ProcessAccessSyncJob::dispatch();

        return back()->with('success', 'Queued '.$queued.' controller write(s). Offline devices remain pending until they answer.');
    }

    public function syncOne(Request $request, Personnel $personnel): RedirectResponse
    {
        Gate::authorize('access.manage');

        $result = $this->access->syncPersonnel($personnel, $request->user());
        $count = count($result['terminals']);

        return back()->with('success', $personnel->name.': '.$result['synced'].' of '.$count
            .' controller assignment(s) confirmed, '.$result['pending'].' pending.');
    }
}
