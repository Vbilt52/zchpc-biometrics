<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ZCHPC Personnel Access Report</title>
    <style>
        @page {
            margin: 22px 25px 30px 25px;
            size: landscape;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5px;
            color: #1e293b;
            line-height: 1.35;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 2px solid #004a86;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .org-name {
            font-size: 15px;
            font-weight: bold;
            color: #004a86;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .report-title {
            font-size: 12px;
            font-weight: bold;
            color: #334155;
            margin: 2px 0 0;
        }
        .report-meta {
            font-size: 8px;
            color: #64748b;
            margin-top: 4px;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .stat-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 6px 10px;
            text-align: center;
        }
        .stat-val {
            font-size: 13px;
            font-weight: bold;
            color: #004a86;
        }
        .stat-lbl {
            font-size: 7.5px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .data-table thead {
            display: table-header-group;
        }
        .data-table th {
            background-color: #004a86;
            color: #ffffff;
            font-weight: bold;
            font-size: 8px;
            padding: 6px 5px;
            text-align: left;
            border: 1px solid #003663;
        }
        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px;
            vertical-align: top;
            font-size: 8px;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .data-table tr {
            page-break-inside: avoid;
        }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-active {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .badge-suspended {
            background-color: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .badge-expired {
            background-color: #fff7ed;
            color: #c2410c;
            border: 1px solid #ffedd5;
        }
        .badge-fp {
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .badge-no-fp {
            background-color: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        .badge-sync-ok {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .badge-sync-pending {
            background-color: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .item-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .item-list li {
            margin-bottom: 2px;
            line-height: 1.25;
        }
        .small-muted {
            font-size: 7px;
            color: #64748b;
        }
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 22px;
            page-break-inside: avoid;
        }
        .sig-cell {
            width: 33.33%;
            padding: 6px 12px;
            font-size: 8px;
            color: #475569;
        }
        .sig-line {
            border-bottom: 1px solid #94a3b8;
            margin-top: 26px;
            margin-bottom: 4px;
        }
        .footer-note {
            margin-top: 10px;
            font-size: 7.5px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="vertical-align: top;">
                <div class="org-name">Zimbabwe Centre for High Performance Computing</div>
                <div class="report-title">Personnel Device Access & Door Permissions Directory</div>
                <div class="report-meta">
                    <strong>Report Filter:</strong> {{ $filterText }} &nbsp;|&nbsp;
                    <strong>Generated:</strong> {{ $generatedAt }} &nbsp;|&nbsp;
                    <strong>Total Listed:</strong> {{ $stats['total_users'] }} People
                </div>
            </td>
            <td style="text-align: right; vertical-align: top; width: 180px;">
                <div style="font-size: 11px; font-weight: bold; color: #004a86;">ACCESS CONTROL SUITE</div>
                <div style="font-size: 7.5px; color: #64748b;">Physical Security & Biometrics</div>
                <div style="font-size: 7.5px; color: #64748b; margin-top: 2px;">Official System Audit Record</div>
            </td>
        </tr>
    </table>

    <table class="stats-table">
        <tr>
            <td style="width: 20%; padding-right: 4px;">
                <div class="stat-card">
                    <div class="stat-val">{{ $stats['total_users'] }}</div>
                    <div class="stat-lbl">Total Registered Users</div>
                </div>
            </td>
            <td style="width: 20%; padding: 0 4px;">
                <div class="stat-card">
                    <div class="stat-val" style="color: #15803d;">{{ $stats['active_users'] }}</div>
                    <div class="stat-lbl">Active Users</div>
                </div>
            </td>
            <td style="width: 20%; padding: 0 4px;">
                <div class="stat-card">
                    <div class="stat-val" style="color: #0369a1;">{{ $stats['fingerprint_enrolled'] }}</div>
                    <div class="stat-lbl">Biometrics Enrolled</div>
                </div>
            </td>
            <td style="width: 20%; padding: 0 4px;">
                <div class="stat-card">
                    <div class="stat-val">{{ $stats['total_doors'] }}</div>
                    <div class="stat-lbl">Doors In Service</div>
                </div>
            </td>
            <td style="width: 20%; padding-left: 4px;">
                <div class="stat-card">
                    <div class="stat-val">{{ $stats['total_terminals'] }}</div>
                    <div class="stat-lbl">Managed Controllers</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 22px; text-align: center;">#</th>
                <th style="width: 65px;">Emp ID</th>
                <th style="width: 120px;">Full Name</th>
                <th style="width: 85px;">Department</th>
                <th style="width: 55px; text-align: center;">Status</th>
                <th style="width: 75px;">Fingerprint</th>
                <th style="width: 155px;">Assigned Devices / Controllers</th>
                <th>Accessible Doors & Rooms</th>
                <th style="width: 80px; text-align: center;">Controller Sync</th>
                <th style="width: 80px;">Validity Period</th>
            </tr>
        </thead>
        <tbody>
        @forelse($people as $index => $p)
            @php
                $active = $p->isActive();
                $statusLabel = $p->accessStateLabel();
                $statusClass = match(strtolower($statusLabel)) {
                    'active' => 'badge-active',
                    'suspended' => 'badge-suspended',
                    'expired' => 'badge-expired',
                    default => 'badge-no-fp',
                };

                $fpConfirmed = $p->fingerprintEnrollments;
                $hasFp = $fpConfirmed->isNotEmpty();

                $allowedTerminals = $p->terminals->where('pivot.access_allowed', true);
                $pendingCount = $p->pendingSyncs->count();
            @endphp
            <tr>
                <td style="text-align: center; color: #64748b;">{{ $index + 1 }}</td>
                <td><strong style="font-family: monospace;">{{ $p->employee_no }}</strong></td>
                <td>
                    <strong>{{ $p->name }}</strong>
                    @if($p->phone_number)
                        <div class="small-muted">Tel: {{ $p->phone_number }}</div>
                    @endif
                </td>
                <td>{{ $p->accessGroup?->name ?? $p->department ?? 'General Staff' }}</td>
                <td style="text-align: center;">
                    <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                </td>
                <td>
                    @if($hasFp)
                        <span class="badge badge-fp">Slot #{{ $fpConfirmed->pluck('finger_id')->unique()->sort()->implode(', #') }}</span>
                    @else
                        <span class="badge badge-no-fp">Not Enrolled</span>
                    @endif
                </td>
                <td>
                    @if($allowedTerminals->isNotEmpty())
                        <ul class="item-list">
                            @foreach($allowedTerminals as $t)
                                <li>
                                    <strong>{{ $t->name }}</strong>
                                    <span class="small-muted">({{ $t->ip }})</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <span style="color: #94a3b8; font-style: italic;">No devices assigned</span>
                    @endif
                </td>
                <td>
                    @if($p->doors->isNotEmpty())
                        <ul class="item-list">
                            @foreach($p->doors as $d)
                                <li>
                                    • {{ $d->name }}
                                    @if($d->building)
                                        <span class="small-muted">[{{ $d->building }}]</span>
                                    @endif
                                    @if($d->code)
                                        <span class="small-muted">({{ $d->code }})</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <span style="color: #94a3b8; font-style: italic;">No doors assigned</span>
                    @endif
                </td>
                <td style="text-align: center;">
                    @if($pendingCount > 0)
                        <span class="badge badge-sync-pending">{{ $pendingCount }} Pending</span>
                    @elseif($allowedTerminals->isNotEmpty())
                        <span class="badge badge-sync-ok">All Confirmed</span>
                    @else
                        <span style="color: #94a3b8;">—</span>
                    @endif
                </td>
                <td>
                    <div style="font-size: 7.5px;">
                        From: {{ $p->begin_time ? $p->begin_time->format('d/m/Y') : 'Immediate' }}<br>
                        To: {{ $p->end_time ? $p->end_time->format('d/m/Y') : 'Permanent' }}
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="10" style="text-align: center; padding: 20px; color: #64748b;">
                    No personnel records matched the selected query.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <table class="signatures-table">
        <tr>
            <td class="sig-cell">
                <div>Prepared By (Systems Administrator):</div>
                <div class="sig-line"></div>
                <div class="small-muted">Signature &amp; Full Name &nbsp;|&nbsp; Date</div>
            </td>
            <td class="sig-cell">
                <div>Verified By (Security Officer):</div>
                <div class="sig-line"></div>
                <div class="small-muted">Signature &amp; Full Name &nbsp;|&nbsp; Date</div>
            </td>
            <td class="sig-cell">
                <div>Approved By (Director / Head of Center):</div>
                <div class="sig-line"></div>
                <div class="small-muted">Signature &amp; Official Stamp &nbsp;|&nbsp; Date</div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        This document is an official electronic extract generated from the Zimbabwe Centre for High Performance Computing (ZCHPC) Access Control Suite.
    </div>
</body>
</html>
