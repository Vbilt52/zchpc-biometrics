<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ZCHPC Personnel Access Directory</title>
    <style>
        body {
            font-family: Calibri, Arial, sans-serif;
            font-size: 10pt;
            color: #1e293b;
            margin: 20px;
        }
        .header {
            border-bottom: 2px solid #004a86;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .title {
            font-size: 16pt;
            font-weight: bold;
            color: #004a86;
            margin: 0;
            text-transform: uppercase;
        }
        .subtitle {
            font-size: 12pt;
            color: #475569;
            margin: 4px 0 0;
        }
        .meta {
            font-size: 9pt;
            color: #64748b;
            margin-top: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th {
            background-color: #004a86;
            color: #ffffff;
            text-align: left;
            padding: 8px 6px;
            font-size: 9.5pt;
            font-weight: bold;
            border: 1px solid #004a86;
        }
        td {
            padding: 6px;
            border: 1px solid #cbd5e1;
            font-size: 9pt;
            vertical-align: top;
        }
        tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .badge {
            font-weight: bold;
        }
        .active { color: #15803d; }
        .suspended { color: #b91c1c; }
        .pending { color: #b45309; }
        .ok { color: #047857; }
        .signatures {
            margin-top: 40px;
            width: 100%;
        }
        .signatures td {
            border: none;
            padding: 10px;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Zimbabwe Centre for High Performance Computing</div>
        <div class="subtitle">Personnel Device Access &amp; Door Permissions Directory</div>
        <div class="meta">Exported on: {{ $generatedAt }} | Total Records: {{ $people->count() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 10%;">Emp ID</th>
                <th style="width: 16%;">Full Name</th>
                <th style="width: 12%;">Department</th>
                <th style="width: 8%;">Status</th>
                <th style="width: 10%;">Fingerprint</th>
                <th style="width: 18%;">Assigned Devices / Terminals</th>
                <th style="width: 21%;">Accessible Doors &amp; Rooms</th>
            </tr>
        </thead>
        <tbody>
        @foreach($people as $index => $p)
            @php
                $statusLabel = $p->accessStateLabel();
                $fpConfirmed = $p->fingerprintEnrollments;
                $hasFp = $fpConfirmed->isNotEmpty();
                $terminals = $p->terminals->where('pivot.access_allowed', true)->map(fn($t) => $t->name . " ({$t->ip})")->implode(', ');
                $doors = $p->doors->map(fn($d) => $d->name . ($d->code ? " [{$d->code}]" : ''))->implode(', ');
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td><strong>{{ $p->employee_no }}</strong></td>
                <td>{{ $p->name }}</td>
                <td>{{ $p->accessGroup?->name ?? $p->department ?? 'General' }}</td>
                <td class="badge {{ strtolower($statusLabel) === 'active' ? 'active' : 'suspended' }}">{{ $statusLabel }}</td>
                <td>{{ $hasFp ? 'Slot #' . $fpConfirmed->pluck('finger_id')->unique()->sort()->implode(', #') : 'Not Enrolled' }}</td>
                <td>{{ $terminals ?: 'None' }}</td>
                <td>{{ $doors ?: 'None' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td style="width: 33%;">
                Prepared by: __________________________<br>
                Systems Administrator
            </td>
            <td style="width: 33%;">
                Verified by: __________________________<br>
                Security Officer
            </td>
            <td style="width: 34%;">
                Approved by: __________________________<br>
                Director
            </td>
        </tr>
    </table>
</body>
</html>
