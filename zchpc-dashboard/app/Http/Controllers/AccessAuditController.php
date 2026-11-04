<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use Illuminate\Http\Request;
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
}
