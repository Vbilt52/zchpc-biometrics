<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fingerprint Attendance Register</title>
    <style>
        @page { margin:26px; }
        body { font-family:DejaVu Sans, sans-serif; font-size:9px; color:#1e293b; }
        h1 { color:#004a86; font-size:17px; margin:0 0 5px; }
        .meta { color:#475569; margin-bottom:12px; }
        .note { background:#edf6fc; border:1px solid #b9d9ed; padding:8px; margin-bottom:12px; }
        table { border-collapse:collapse; width:100%; }
        thead { display:table-header-group; }
        th { background:#004a86; color:white; text-align:left; padding:7px; }
        td { border:1px solid #dbe4ee; padding:7px; vertical-align:top; overflow-wrap:anywhere; }
        tr { page-break-inside:avoid; }
        .small { font-size:8px; color:#64748b; margin-top:3px; }
        .signatures { margin-top:24px; }
    </style>
</head>
<body>
    <h1>ZCHPC · Fingerprint Attendance Register</h1>
    <div class="meta">Period: {{ $filters['from'] }} to {{ $filters['to'] }} · Timezone: {{ $timezone }} · Rows: {{ $rows->count() }}<br>
        Generated: {{ now($timezone)->format('d M Y H:i:s') }}
        @if($filters['search'] !== '') · Owner search: {{ $filters['search'] }} @endif
        @if($filters['terminal_id']) · Terminal ID: {{ $filters['terminal_id'] }} @endif
        @if($filters['access_group_id']) · Department ID: {{ $filters['access_group_id'] }} @endif
        @if($filters['status'] !== '') · Status: {{ $register->statusLabel($filters['status']) }} @endif
    </div>
    <div class="note">Login is the first successful fingerprint entry for each owner, terminal and day. The exit button does not identify who left. Logout times are manual operator records; a blank logout means it was not captured. Imported records do not establish absence or continuous hours worked.</div>
    <table>
        <thead><tr><th>Date</th><th>Fingerprint owner / ID</th><th>Terminal</th><th>Login</th><th>Logout</th><th>Notes / source</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td><strong>{{ $row['owner'] }}</strong><div class="small">ID: {{ $row['employee_no'] }}<br>{{ $row['department'] ?? '' }}</div></td>
                <td>{{ $row['terminal_name'] }}</td>
                <td>{{ $row['first_in']->format('H:i:s') }}<div class="small">Fingerprint</div></td>
                <td>{{ $row['logout']?->format('d M Y H:i:s') ?? 'Not captured' }}</td>
                <td>{{ $register->statusLabel($row['status']) }}<div class="small">{{ $row['scan_count'] }} scan(s) · Latest: {{ $row['last_scan']->format('H:i:s') }}</div>
                    @if($row['checkout'])<div class="small">Recorded by: {{ $row['checkout']->recorded_by_name }}<br>{{ $row['checkout']->reason }}</div>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6">No matching successful fingerprint entries.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="signatures">Prepared by: __________________________ &nbsp;&nbsp; Reviewed by: __________________________ &nbsp;&nbsp; Date: __________________</div>
</body>
</html>
