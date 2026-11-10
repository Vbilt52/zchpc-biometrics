<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccessAuditController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('access.audit');

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:48'],
            'outcome' => ['nullable', 'in:success,failure,info,warning'],
            'search' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = AccessAuditLog::query()->with(['door', 'terminal']);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Scope logs for HR Officer to personnel/identity events if not full admin or audit officer
        if ($user && $user->isHrOfficer() && ! $user->isFullAdmin() && ! $user->isAuditOfficer() && ! $user->isExecutive()) {
            $query->where(function ($q): void {
                $q->whereIn('action', [
                    'personnel_created',
                    'personnel_updated',
                    'personnel_deleted',
                    'user_created',
                    'user_updated',
                    'user_deployed',
                ])->orWhere('subject_type', 'personnel');
            });
        }

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['outcome'])) {
            $query->where('outcome', $validated['outcome']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($query) use ($search): void {
                $query->where('user_name', 'like', '%'.$search.'%')
                    ->orWhere('subject_label', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from'].' 00:00:00');
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'].' 23:59:59');
        }

        return view('access.audit', [
            'logs' => $query->latest('id')->paginate(50)->withQueryString(),
            'actions' => AccessAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'filters' => $validated,
        ]);
    }

    public function download(Request $request): Response
    {
        Gate::authorize('access.audit');

        $validated = $request->validate([
            'action'  => ['nullable', 'string', 'max:48'],
            'outcome' => ['nullable', 'in:success,failure,info,warning'],
            'search'  => ['nullable', 'string', 'max:80'],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
        ]);

        $query = AccessAuditLog::query()->with(['door', 'terminal']);

        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user && $user->isHrOfficer() && ! $user->isFullAdmin() && ! $user->isAuditOfficer() && ! $user->isExecutive()) {
            $query->where(function ($q): void {
                $q->whereIn('action', [
                    'personnel_created', 'personnel_updated', 'personnel_deleted',
                    'user_created', 'user_updated', 'user_deployed',
                ])->orWhere('subject_type', 'personnel');
            });
        }

        if (! empty($validated['action']))  { $query->where('action', $validated['action']); }
        if (! empty($validated['outcome'])) { $query->where('outcome', $validated['outcome']); }
        if (! empty($validated['from']))    { $query->where('created_at', '>=', $validated['from'].' 00:00:00'); }
        if (! empty($validated['to']))      { $query->where('created_at', '<=', $validated['to'].' 23:59:59'); }

        if (! empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($q) use ($s): void {
                $q->where('user_name', 'like', "%{$s}%")
                  ->orWhere('subject_label', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $logs = $query->latest('id')->get();

        $csv  = "Time,Actor,Action,Subject,Terminal,Outcome,Detail,IP Address\n";
        foreach ($logs as $log) {
            $csv .= implode(',', [
                '"'.($log->created_at?->format('d M Y H:i:s') ?? '').'"',
                '"'.str_replace('"', '""', $log->user_name ?? '').'"',
                '"'.str_replace('"', '""', $log->actionLabel()).'"',
                '"'.str_replace('"', '""', $log->subject_label ?? '').'"',
                '"'.str_replace('"', '""', $log->terminal?->name ?? '').'"',
                '"'.($log->outcome ?? '').'"',
                '"'.str_replace('"', '""', $log->description ?? '').'"',
                '"'.($log->ip_address ?? '').'"',
            ])."\n";
        }

        $filename = 'zchpc-audit-logs-'.now()->format('Y-m-d_His').'.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
