<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $q = trim($validated['q'] ?? '');
        if (mb_strlen($q) < 2) {
            return response()->json([
                'personnel' => [],
                'devices' => [],
                'events' => [],
                'total' => 0,
            ]);
        }

        $term = "%{$q}%";

        $personnel = Personnel::query()
            ->where('name', 'like', $term)
            ->orWhere('employee_no', 'like', $term)->orWhere('personnel_code', 'like', $term)
            ->orWhere('department', 'like', $term)
            ->orWhere('phone_number', 'like', $term)
            ->limit(5)
            ->get(['id', 'name', 'employee_no', 'personnel_code', 'department', 'status'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->name,
                'subtitle' => 'ID: '.$p->employee_id.($p->department ? ' · '.$p->department : ''),
                'url' => route('access.users.show', $p->id),
                'icon' => 'fa-solid fa-user',
                'badge' => ucfirst($p->status ?? 'active'),
            ]);

        $devices = Terminal::query()
            ->where('name', 'like', $term)
            ->orWhere('ip', 'like', $term)
            ->orWhere('location', 'like', $term)
            ->limit(5)
            ->get(['id', 'name', 'ip', 'port', 'location', 'status'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->name,
                'subtitle' => "{$t->ip}:{$t->port}".($t->location ? " · {$t->location}" : ''),
                'url' => route('devices.index', ['search' => $t->name]),
                'icon' => 'fa-solid fa-server',
                'badge' => ucfirst($t->status ?? 'unknown'),
            ]);

        $zone = config('attendance.timezone');
        $events = AccessEvent::query()
            ->with(['terminal', 'personnel'])
            ->where('person_name', 'like', $term)
            ->orWhere('employee_no', 'like', $term)
            ->orWhereHas('personnel', fn ($query) => $query->where('name', 'like', $term))
            ->latest('occurred_at')
            ->limit(5)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->displayName(),
                'subtitle' => ($e->terminal?->name ?? 'Terminal').' · '.($e->occurred_at ? $e->occurred_at->copy()->timezone($zone)->format('M d, H:i') : ''),
                'url' => route('events.index', ['search' => $e->employee_no ?: $e->person_name]),
                'icon' => 'fa-solid fa-bolt',
                'badge' => $e->outcomeLabel(),
            ]);

        return response()->json([
            'personnel' => $personnel,
            'devices' => $devices,
            'events' => $events,
            'total' => $personnel->count() + $devices->count() + $events->count(),
        ]);
    }
}
