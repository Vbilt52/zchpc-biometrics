<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessAuditController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access.audit');
        $filters = $this->filters($request);

        return view('access.audit', [
            'logs' => $this->query($request, $filters)->with(['door', 'terminal'])->latest('id')->paginate(50)->withQueryString(),
            'actions' => $this->query($request, [])->distinct()->orderBy('action')->pluck('action'),
            'filters' => $filters,
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        Gate::authorize('access.audit');
        $query = $this->query($request, $this->filters($request))->with(['door', 'terminal']);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Time ('.config('attendance.timezone').')', 'Actor', 'Action', 'Subject', 'Door', 'Terminal', 'Outcome', 'Detail', 'IP address'], escape: '');
            foreach ($query->lazyByIdDesc(500) as $log) {
                $values = [$log->created_at?->timezone(config('attendance.timezone'))->format('Y-m-d H:i:s'), $log->user_name,
                    $log->actionLabel(), $log->subject_label, $log->door?->name, $log->terminal?->name, $log->outcome, $log->description, $log->ip_address];
                $values = array_map(static function ($value): string {
                    $value = (string) $value;

                    return preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
                }, $values);
                fputcsv($stream, $values, escape: '');
            }
            fclose($stream);
        }, 'zchpc-audit-logs-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'string', 'max:48'],
            'outcome' => ['nullable', 'in:success,failure,info,warning'],
            'search' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    private function query(Request $request, array $filters): Builder
    {
        $query = AccessAuditLog::query();
        $user = $request->user();
        if ($user->isHrOfficer() && ! $user->isFullAdmin() && ! $user->isAuditOfficer() && ! $user->isExecutive()) {
            $query->where(fn (Builder $q) => $q->whereIn('action', ['personnel_created', 'personnel_updated', 'personnel_deleted', 'user_created', 'user_updated', 'user_deployed'])->orWhere('subject_type', 'personnel'));
        }
        foreach (['action', 'outcome'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('user_name', 'like', $term)->orWhere('subject_label', 'like', $term)->orWhere('description', 'like', $term));
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], config('attendance.timezone'))->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], config('attendance.timezone'))->addDay()->startOfDay()->utc());
        }

        return $query;
    }
}
