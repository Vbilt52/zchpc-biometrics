<?php

namespace App\Http\Controllers;

use App\Models\AccessGroup;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\PersonnelDoor;
use App\Models\Terminal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessMatrixController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access.view');

        $filters = $this->filters($request);
        $columns = $this->columns($filters);
        $people = $this->rows($filters);
        $people->setCollection($this->decorate($people->getCollection(), $columns));

        return view('access.matrix', [
            'people' => $people,
            'columns' => $columns,
            'allDoors' => Door::query()->orderBy('building')->orderBy('sort_order')->orderBy('name')->get(),
            'doorCounts' => $this->doorCounts($columns),
            'departments' => AccessGroup::orderBy('name')->get(),
            'buildings' => Door::query()->whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
            'terminals' => Terminal::apiManaged()->orderBy('phase')->orderBy('name')->get(),
            'filters' => $filters,
            'truncated' => $columns->count() >= 60,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('access.view');

        $filters = $this->filters($request);
        $columns = $this->columns($filters);
        $rows = $this->decorate(collect($this->rows($filters)->items()), $columns);

        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_merge(
                ['User', 'Employee ID', 'Department', 'Access status'],
                $columns->map(fn (Door $door): string => $door->name)->all(),
                ['Configured doors', 'Confirmed doors', 'Pending doors'],
            ));

            foreach ($rows as $row) {
                $person = $row['person'];

                fputcsv($out, array_merge(
                    [$person->name, $person->employee_no, $person->accessGroup?->name ?? $person->department, $person->accessStateLabel()],
                    $columns->map(fn (Door $door): string => $this->cellLabel($row['cells']->get($door->id, 'none')))->all(),
                    [$row['configured'], $row['granted'], $row['pending']],
                ));
            }

            fclose($out);
        }, 'zchpc access matrix '.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, mixed> $filters */
    private function columns(array $filters): Collection
    {
        return Door::query()
            ->with('terminal')
            ->when(! empty($filters['door']), fn ($query) => $query->whereKey($filters['door']))
            ->when(! empty($filters['building']), fn ($query) => $query->where('building', $filters['building']))
            ->when(! empty($filters['terminal']), fn ($query) => $query->where('terminal_id', $filters['terminal']))
            ->orderBy('building')->orderBy('floor')->orderBy('sort_order')->orderBy('name')
            ->limit(60)
            ->get();
    }

    /** @param array<string, mixed> $filters */
    private function rows(array $filters)
    {
        return Personnel::query()
            ->onboarded()
            ->with(['doors', 'terminals', 'pendingSyncs', 'accessGroup'])
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $search = $filters['search'];
                $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('employee_no', 'like', '%'.$search.'%'));
            })
            ->when(! empty($filters['department']), fn ($query) => $query->where('access_group_id', $filters['department']))
            ->when(! empty($filters['door']), fn ($query) => $query->whereHas('doors', fn ($query) => $query
                ->where('doors.id', $filters['door'])))
            ->when(empty($filters['door']) && ! empty($filters['building']), fn ($query) => $query->whereHas('doors', fn ($query) => $query
                ->where('doors.building', $filters['building'])))
            ->when(empty($filters['door']) && ! empty($filters['terminal']), fn ($query) => $query->whereHas('doors', fn ($query) => $query
                ->where('doors.terminal_id', $filters['terminal'])))
            ->when(($filters['state'] ?? '') === 'pending', fn ($query) => $query->whereHas('pendingSyncs'))
            ->when(($filters['state'] ?? '') === 'no_access', fn ($query) => $query->whereDoesntHave('doors'))
            ->when(($filters['state'] ?? '') === 'active', fn ($query) => $query->where('status', Personnel::STATUS_ACTIVE))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @param  Collection<int, Personnel>  $people
     * @param  Collection<int, Door>  $columns
     * @return Collection<int, array{person: Personnel, cells: Collection<int, string>, configured: int, granted: int, pending: int}>
     */
    private function decorate(Collection $people, Collection $columns): Collection
    {
        return $people->map(function (Personnel $person) use ($columns): array {
            $grants = $person->doors->keyBy('id');

            $cells = $columns->mapWithKeys(function (Door $door) use ($person, $grants): array {
                $grant = $grants->get($door->id);

                if ($grant === null) {
                    return [$door->id => 'none'];
                }

                if ($grant->pivot->expires_at !== null && Carbon::parse($grant->pivot->expires_at)->isPast()) {
                    return [$door->id => 'expired'];
                }

                $terminal = $person->terminals->firstWhere('id', $door->terminal_id);

                if ($terminal === null) {
                    return [$door->id => 'not_deployed'];
                }

                if (! (bool) $terminal->pivot->access_allowed) {
                    if ($person->isSuspended()) {
                        return [$door->id => 'suspended'];
                    }

                    return [$door->id => $terminal->pivot->sync_status === 'confirmed' ? 'revoked' : 'pending'];
                }

                if (in_array((string) $terminal->pivot->sync_status, ['unverified', 'imported'], true)) {
                    return [$door->id => 'unverified'];
                }

                return [$door->id => $terminal->pivot->sync_status === 'confirmed' ? 'granted' : 'pending'];
            });

            $counts = $cells->countBy();

            return [
                'person' => $person,
                'cells' => $cells,
                'configured' => $cells->filter(fn (string $state): bool => $state !== 'none')->count(),
                'granted' => (int) $counts->get('granted', 0),
                'pending' => (int) ($counts->get('pending', 0) + $counts->get('not_deployed', 0)),
            ];
        });
    }

    /** @param Collection<int, Door> $columns */
    private function doorCounts(Collection $columns): Collection
    {
        return PersonnelDoor::query()
            ->join('personnel', 'personnel.id', '=', 'personnel_door.personnel_id')
            ->where('personnel.status', '!=', Personnel::STATUS_DRAFT)
            ->whereIn('personnel_door.door_id', $columns->pluck('id'))
            ->selectRaw('personnel_door.door_id as door_id, count(*) as total')
            ->groupBy('personnel_door.door_id')
            ->pluck('total', 'door_id');
    }

    public function cellLabel(string $state): string
    {
        return match ($state) {
            'granted' => 'Allowed',
            'pending' => 'Allowed · awaiting controller',
            'not_deployed' => 'Not yet deployed',
            'suspended' => 'Suspended',
            'revoked' => 'Revoked',
            'unverified' => 'Configured · not verified',
            'expired' => 'Expired',
            default => 'Denied',
        };
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'integer', 'exists:access_groups,id'],
            'door' => ['nullable', 'integer', 'exists:doors,id'],
            'building' => ['nullable', 'string', 'max:80'],
            'terminal' => ['nullable', 'integer', 'exists:terminals,id'],
            'state' => ['nullable', 'in:active,pending,no_access'],
        ]);

        return array_filter($validated, fn ($value): bool => $value !== null && $value !== '');
    }
}
