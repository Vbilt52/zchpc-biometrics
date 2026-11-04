<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ZCHPC Access Event Log</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1e293b; font-size: 10px; }
        .header { border-bottom: 2px solid #004a86; padding-bottom: 10px; margin-bottom: 14px; }
        h1 { color: #004a86; font-size: 18px; margin: 0; }
        h2 { color: #475569; font-size: 12px; font-weight: normal; margin: 3px 0 0; }
        .meta { color: #64748b; font-size: 9px; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #004a86; color: #ffffff; text-align: left; padding: 7px; font-size: 9px; }
        td { border: 1px solid #dbe4ee; padding: 6px; vertical-align: top; }
        tr:nth-child(even) { background: #f8fafc; }
        .granted { color: #166534; font-weight: bold; }
        .denied { color: #991b1b; font-weight: bold; }
        .exit { color: #0369a1; font-weight: bold; }
        .info { color: #475569; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Zimbabwe Centre for High Performance Computing</h1>
        <h2>{{ ($filters['outcome'] ?? 'all') === 'denied' ? 'Failed Access Attempts' : 'Access Control Event Audit Log' }}</h2>
        <div class="meta">Period: {{ $filters['from']->format('d M Y H:i') }} to {{ $filters['to']->format('d M Y H:i') }} | Records: {{ $events->count() }} | Generated: {{ now()->format('d M Y H:i:s') }}</div>
    </div>
    <table>
        <thead><tr><th>Time</th><th>Terminal</th><th>Person</th><th>Employee ID</th><th>Method</th><th>Result</th></tr></thead>
        <tbody>
            @forelse($events as $event)
                @php $outcome = $event->outcomeLabel(); @endphp
                <tr>
                    <td>{{ app(\App\Services\AttendanceRegisterService::class)->eventTime($event)?->format('d M Y H:i:s') ?? 'Unknown' }}</td>
                    <td>{{ $event->terminal?->name ?? 'Removed terminal' }}</td>
                    <td>{{ $event->person_name ?: ($event->personnel?->name ?: 'Unknown person') }}</td>
                    <td>{{ $event->employee_no ?? '—' }}</td>
                    <td>{{ $event->methodLabel() }}</td>
                    <td class="{{ strtolower($outcome) }}">{{ $outcome }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;">No records match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>


