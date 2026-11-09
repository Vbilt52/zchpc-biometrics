@extends('layouts.app')

@section('title', 'Personnel - ZCHPC Access Control')
@section('header', 'Personnel Management')

@section('content')

{{-- Alerts --}}
@if(session('success'))
<div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.35);border-radius:var(--radius-md);padding:0.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.75rem;animation:fadeIn .4s ease;">
    <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
    <span style="color:#065f46;font-size:0.9rem;">{{ session('success') }}</span>
</div>
@endif
@if(session('error'))
<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius-md);padding:0.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.75rem;">
    <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
    <span style="color:#7f1d1d;font-size:0.9rem;">{{ session('error') }}</span>
</div>
@endif

@if($noTerminals)
<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:var(--radius-md);padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;">
    <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;font-size:1.25rem;"></i>
    <div>
        <strong style="color:#92400e;">No terminals configured.</strong>
        <span style="color:#78350f;"> Please <a href="{{ route('terminals.create') }}" style="color:var(--zchpc-primary);font-weight:600;">add a terminal</a> before managing personnel.</span>
    </div>
</div>
@endif

{{-- Top bar: Search + Actions --}}
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">

    {{-- Search & Filter --}}
    <form method="GET" action="{{ route('personnel.index') }}" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            @if(request('terminal_id'))<input type="hidden" name="terminal_id" value="{{ request('terminal_id') }}">@endif

        <div style="position:relative;">
            <i class="fa-solid fa-search" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--zchpc-text-muted);font-size:0.8rem;"></i>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="e.g. Prince, employee ID or phone"
                style="padding:0.6rem 1rem 0.6rem 2.25rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);font-size:0.875rem;outline:none;width:260px;font-family:'Inter',sans-serif;"
                onfocus="this.style.borderColor='var(--zchpc-primary)'" onblur="this.style.borderColor='var(--zchpc-border)'">
        </div>
        <select name="role" onchange="this.form.submit()"
            style="padding:0.6rem 1rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);font-family:'Inter',sans-serif;font-size:0.875rem;outline:none;background:white;">
            <option value="">All Roles</option>
            <option value="normal"    {{ request('role')==='normal'    ? 'selected':'' }}>Normal User</option>
            <option value="visitor"   {{ request('role')==='visitor'   ? 'selected':'' }}>Visitor</option>
            <option value="blocklist" {{ request('role')==='blocklist'  ? 'selected':'' }}>Blocklist</option>
        </select>
        <select name="access_group" onchange="this.form.submit()"
            style="padding:0.6rem 1rem;border:1px solid {{ request('access_group') ? 'var(--zchpc-primary)' : 'var(--zchpc-border)' }};border-radius:var(--radius-md);font-family:'Inter',sans-serif;font-size:0.875rem;outline:none;background:white;{{ request('access_group') ? 'color:var(--zchpc-primary);font-weight:600;' : '' }}">
            <option value="">All Access Groups</option>
            @foreach($accessGroups as $accessGroup)
            <option value="{{ $accessGroup->id }}" {{ (string) request('access_group') === (string) $accessGroup->id ? 'selected':'' }}>{{ $accessGroup->name }}</option>
            @endforeach
        </select>
        @if(request('search') || request('role') || request('access_group'))
        <a href="{{ route('personnel.index') }}" class="btn btn-outline" style="padding:0.6rem 0.875rem;">
            <i class="fa-solid fa-xmark"></i>
        </a>
        @endif
    </form>

    {{-- Action buttons --}}
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        {{-- Import from specific terminal --}}
        @if(!$noTerminals)
        <div style="position:relative;" id="importDropdownWrap">
            <button onclick="document.getElementById('importDropdown').classList.toggle('open')"
                class="btn btn-outline" style="gap:0.4rem;">
                <i class="fa-solid fa-file-import"></i> Import from Device
                <i class="fa-solid fa-chevron-down" style="font-size:0.7rem;"></i>
            </button>
            <div id="importDropdown" style="display:none;position:absolute;right:0;top:calc(100% + 6px);min-width:220px;background:white;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:50;overflow:hidden;">
                <div style="padding:0.5rem 0;">
                    <form action="{{ route('personnel.import.all') }}" method="POST" style="margin:0;">
                        @csrf
                        <button type="submit" style="width:100%;display:flex;align-items:center;gap:0.6rem;padding:0.6rem 1rem;font-size:0.875rem;color:var(--zchpc-text);background:none;border:none;cursor:pointer;text-align:left;font-weight:600;border-bottom:1px solid var(--zchpc-border);">
                        <i class="fa-solid fa-layer-group" style="color:var(--zchpc-primary);"></i> Import from All
                        </button>
                    </form>
                    @foreach($terminals as $terminal)
                    <form action="{{ route('personnel.import', $terminal) }}" method="POST" style="margin:0;">
                        @csrf
                        <button type="submit"
                            style="width:100%;display:flex;align-items:center;gap:0.6rem;padding:0.6rem 1rem;font-size:0.875rem;color:var(--zchpc-text);background:none;border:none;cursor:pointer;text-align:left;"
                            onmouseover="this.style.background='var(--zchpc-bg)'" onmouseout="this.style.background=''">
                            <i class="fa-solid fa-server" style="color:var(--zchpc-primary);font-size:0.75rem;"></i>
                            {{ $terminal->name }}
                            <span style="margin-left:auto;font-size:0.7rem;color:var(--zchpc-text-muted);">{{ $terminal->phase }}</span>
                        </button>
                    </form>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Sync Assignments --}}
        <form action="{{ route('personnel.sync.all') }}" method="POST" style="margin:0;" onsubmit="return confirm('Refresh existing device assignments? No new access will be granted.')">
            @csrf
            <button type="submit" class="btn btn-outline">
                <i class="fa-solid fa-rotate"></i> Sync Assignments
            </button>
        </form>

        @endif

        {{-- Export Dropdown / Buttons --}}
        <div style="display:flex;gap:0.4rem;">
            <button type="button" onclick="downloadPersonnelPDF()" class="btn btn-outline" style="color:#b91c1c;border-color:#b91c1c;gap:0.4rem;">
                <i class="fa-solid fa-file-pdf"></i> PDF
            </button>
            <a href="{{ route('personnel.export.word') }}" class="btn btn-outline" style="color:#004a86;border-color:#004a86;gap:0.4rem;">
                <i class="fa-solid fa-file-word"></i> Word
            </a>
            <a href="{{ route('personnel.export') }}" class="btn btn-outline" style="gap:0.4rem;" title="Download CSV">
                <i class="fa-solid fa-file-csv"></i> CSV
            </a>
        </div>

        <a href="{{ route('personnel.create') }}" class="btn btn-primary" style="font-weight:600;">
            <i class="fa-solid fa-plus"></i> Add Person
        </a>
    </div>
</div>

<!-- Print Container for Personnel PDF -->
<div id="personnel-pdf-container" style="display: none;"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPersonnelPDF() {
    const btn = event.currentTarget || document.querySelector('button[onclick="downloadPersonnelPDF()"]');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting...';
        btn.disabled = true;
    }

    const tableHtml = document.querySelector('.table-wrapper table').outerHTML;
    
    const pdfContent = `
        <div style="padding: 24px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #1e293b;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; border-bottom: 2px solid #0c70a2; padding-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <img src="{{ asset('images/zchpc-logo.png') }}" style="height: 55px; width: auto;" alt="ZCHPC Logo" onerror="this.style.display='none'">
                    <div>
                        <h2 style="margin: 0; color: #0c70a2; font-size: 18px; font-weight: 800; letter-spacing: -0.02em;">Zimbabwe Centre for High Performance Computing</h2>
                        <h3 style="margin: 4px 0 0; color: #475569; font-size: 14px; font-weight: 600;">Official Personnel Directory & Access Control Permissions</h3>
                    </div>
                </div>
                <div style="text-align: right; font-size: 11px; color: #64748b;">
                    <p style="margin: 0;"><strong>Date Exported:</strong> ${new Date().toLocaleString()}</p>
                    <p style="margin: 3px 0 0;"><strong>Document:</strong> zchpc access list</p>
                </div>
            </div>
            <style>
                table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 10px; }
                th { background-color: #f1f5f9; color: #334155; text-align: left; padding: 8px 10px; border: 1px solid #cbd5e1; font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: 0.05em; }
                td { padding: 8px 10px; border: 1px solid #e2e8f0; color: #1e293b; vertical-align: middle; }
                tr:nth-child(even) { background-color: #f8fafc; }
                .badge { padding: 3px 7px; border-radius: 4px; font-size: 10px; font-weight: 600; display: inline-block; }
                .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
                .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
                .badge-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
                /* Hide action buttons column in PDF */
                th:last-child, td:last-child { display: none; }
            </style>
            ${tableHtml}
        </div>
    `;

    const container = document.getElementById('personnel-pdf-container');
    container.innerHTML = pdfContent;
    container.style.display = 'block';

    const opt = {
        margin:       [0.4, 0.4, 0.4, 0.4],
        filename:     'zchpc access list.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'in', format: 'a4', orientation: 'landscape' },
        pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
    };

    html2pdf().set(opt).from(container).save().then(() => {
        container.style.display = 'none';
        container.innerHTML = '';
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }).catch(err => {
        console.error('PDF generation error:', err);
        container.style.display = 'none';
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    });
}
</script>

{{-- Table --}}
<div class="table-card animate-fade-in delay-100">
    <div class="table-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2>Personnel
            <span style="font-size:0.875rem;color:var(--zchpc-text-muted);font-weight:400;font-family:'Inter',sans-serif;">
                ({{ $personnel->total() }} records)
            </span>
        </h2>
        @if(request('terminal_id'))
            <p style="font-size:.85rem;">Assigned to {{ $terminals->firstWhere('id', (int) request('terminal_id'))?->name ?? 'selected device' }} · <a href="{{ route('personnel.index') }}">Show all people</a></p>
        @endif
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID / Employee No.</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Access Window</th>
                    <th>Status</th>
                    <th>Device Access</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($personnel as $person)
                <tr>
                    <td style="font-family:monospace;font-weight:600;color:var(--zchpc-primary);">
                        {{ $person->employee_no }}
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <div class="avatar" style="width:36px;height:36px;font-size:0.875rem;flex-shrink:0;
                                {{ $person->user_type === 'blocklist' ? 'background:var(--danger);' : ($person->user_type === 'visitor' ? 'background:#f59e0b;' : '') }}">
                                {{ strtoupper(substr($person->name, 0, 1)) }}
                            </div>
                            <div>
                                <div style="font-weight:500;">{{ $person->name }}</div>
                                @if($person->phone_number)
                                <div style="font-size:0.75rem;color:var(--zchpc-text-muted);">
                                    <i class="fa-solid fa-phone" style="font-size:0.65rem;"></i> {{ $person->phone_number }}
                                </div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge {{ $person->roleBadgeClass() }}">{{ $person->roleLabel() }}</span>
                    </td>
                    @php
                        $deptStyles = [
                            'Supercomputing' => 'background:rgba(99,102,241,0.12);color:#4f46e5;',
                            'Applications'   => 'background:rgba(16,185,129,0.12);color:#059669;',
                            'Administration' => 'background:rgba(245,158,11,0.12);color:#d97706;',
                            'ZINGSA'         => 'background:rgba(239,68,68,0.12);color:#dc2626;',
                        ];
                        $deptIcons = [
                            'Supercomputing' => 'fa-microchip',
                            'Applications'   => 'fa-code',
                            'Administration' => 'fa-building',
                            'ZINGSA'         => 'fa-satellite',
                        ];
                        $groupName = $person->accessGroup?->name ?? $person->department;
                        $ds = $deptStyles[$groupName] ?? 'background:rgba(100,116,139,0.12);color:#64748b;';
                        $di = $deptIcons[$groupName] ?? 'fa-users';
                    @endphp
                    <td>
                        @if($groupName)
                        <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.65rem;border-radius:9999px;font-size:0.72rem;font-weight:600;{{ $ds }}">
                            <i class="fa-solid {{ $di }}" style="font-size:0.65rem;"></i>
                            {{ $person->accessGroup?->name ?? $person->department }}
                        </span>
                        @else
                        <span style="font-size:0.78rem;color:var(--zchpc-text-muted);opacity:0.6;">—</span>
                        @endif
                    </td>
                    <td style="font-size:0.8rem;color:var(--zchpc-text-muted);">
                        @if($person->begin_time && $person->end_time)
                            <div>{{ $person->begin_time->copy()->timezone(config('attendance.timezone'))->format('d M Y') }}</div>
                            <div>→ {{ $person->end_time->copy()->timezone(config('attendance.timezone'))->format('d M Y') }}</div>
                        @else
                            <span style="opacity:0.4;">—</span>
                        @endif
                    </td>
                    <td>
                        @if($person->isActive())
                            <span class="badge badge-success">Active</span>
                        @else
                            <span class="badge badge-danger">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <strong>{{ $person->terminals->where('pivot.access_allowed', true)->count() }} / {{ $terminals->count() }} assigned</strong>
                        @foreach($terminals as $device)
                            @php
                                $assignment = $person->terminals->firstWhere('id', $device->id);
                            @endphp
                            <div style="font-size:.75rem;margin-top:.25rem;">{{ $device->name }}: {{ $assignment?->pivot->access_allowed ? 'Assigned' : 'Not assigned' }}
                            @if($assignment?->pivot->sync_status === 'pending')<strong style="color:#b45309;"> · Pending</strong>@endif</div>
                        @endforeach
                    </td>
                    <td>
                        <div style="display:flex;gap:0.4rem;">
                            <a href="{{ route('personnel.show', $person) }}" class="btn btn-outline" style="padding:0.35rem 0.7rem;font-size:0.75rem;" title="View">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <a href="{{ route('personnel.edit', $person) }}" class="btn btn-outline" style="padding:0.35rem 0.7rem;font-size:0.75rem;" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <form action="{{ route('personnel.destroy', $person) }}" method="POST" style="display:inline;"
                                onsubmit="return confirm('Remove {{ addslashes($person->name) }} from all terminals and the local database?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-outline" style="padding:0.35rem 0.7rem;font-size:0.75rem;color:var(--danger);" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;padding:3.5rem;color:var(--zchpc-text-muted);">
                        <i class="fa-solid fa-users" style="font-size:2.5rem;display:block;margin-bottom:1rem;opacity:0.2;"></i>
                        <div style="font-size:1rem;font-weight:500;margin-bottom:0.5rem;">No personnel records yet</div>
                        <div style="font-size:0.875rem;">Use <strong>Import from Device</strong> to pull existing users, or <strong>Add Person</strong> to create a new one.</div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($personnel->hasPages() || $personnel->total() > 0)
    <div style="padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--zchpc-border); background: #ffffff; flex-wrap: wrap; gap: 1rem;">
        {{-- Record Count Info --}}
        <div style="font-size: 0.875rem; color: var(--zchpc-text-muted);">
            Showing <strong style="color: var(--zchpc-text); font-weight: 600;">{{ $personnel->firstItem() ?? 0 }}</strong> to <strong style="color: var(--zchpc-text); font-weight: 600;">{{ $personnel->lastItem() ?? 0 }}</strong> of <strong style="color: var(--zchpc-text); font-weight: 600;">{{ $personnel->total() }}</strong> personnel
        </div>

        {{-- Professional Navigation Buttons --}}
        <div style="display: flex; align-items: center; gap: 0.35rem;">
            {{-- Previous Button --}}
            @if ($personnel->onFirstPage())
                <span style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.45rem 0.95rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: #94a3b8; background: #f8fafc; cursor: not-allowed; user-select: none;">
                    <i class="fa-solid fa-chevron-left" style="font-size: 0.75rem;"></i> Previous
                </span>
            @else
                <a href="{{ $personnel->previousPageUrl() }}" style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.45rem 0.95rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: var(--zchpc-text); background: #ffffff; text-decoration: none; transition: var(--transition); box-shadow: var(--shadow-sm);" onmouseover="this.style.borderColor='var(--zchpc-primary)';this.style.color='var(--zchpc-primary)';" onmouseout="this.style.borderColor='var(--zchpc-border)';this.style.color='var(--zchpc-text)';">
                    <i class="fa-solid fa-chevron-left" style="font-size: 0.75rem;"></i> Previous
                </a>
            @endif

            {{-- Numeric Page Buttons --}}
            @php
                $start = max(1, $personnel->currentPage() - 2);
                $end   = min($personnel->lastPage(), $personnel->currentPage() + 2);
            @endphp

            @if($start > 1)
                <a href="{{ $personnel->url(1) }}" style="display: inline-flex; align-items: center; justify-content: center; min-width: 2.2rem; height: 2.2rem; padding: 0 0.5rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: var(--zchpc-text); background: #ffffff; text-decoration: none; transition: var(--transition);" onmouseover="this.style.borderColor='var(--zchpc-primary)';this.style.color='var(--zchpc-primary)';" onmouseout="this.style.borderColor='var(--zchpc-border)';this.style.color='var(--zchpc-text)';">
                    1
                </a>
                @if($start > 2)
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; color: var(--zchpc-text-muted);">...</span>
                @endif
            @endif

            @foreach ($personnel->getUrlRange($start, $end) as $page => $url)
                @if ($page == $personnel->currentPage())
                    <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 2.2rem; height: 2.2rem; padding: 0 0.5rem; font-size: 0.85rem; font-weight: 600; border-radius: var(--radius-md); background: var(--zchpc-primary); color: #ffffff; border: 1px solid var(--zchpc-primary); user-select: none; box-shadow: 0 2px 4px rgba(0,74,134,0.25);">
                        {{ $page }}
                    </span>
                @else
                    <a href="{{ $url }}" style="display: inline-flex; align-items: center; justify-content: center; min-width: 2.2rem; height: 2.2rem; padding: 0 0.5rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: var(--zchpc-text); background: #ffffff; text-decoration: none; transition: var(--transition);" onmouseover="this.style.borderColor='var(--zchpc-primary)';this.style.color='var(--zchpc-primary)';" onmouseout="this.style.borderColor='var(--zchpc-border)';this.style.color='var(--zchpc-text)';">
                        {{ $page }}
                    </a>
                @endif
            @endforeach

            @if($end < $personnel->lastPage())
                @if($end < $personnel->lastPage() - 1)
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; color: var(--zchpc-text-muted);">...</span>
                @endif
                <a href="{{ $personnel->url($personnel->lastPage()) }}" style="display: inline-flex; align-items: center; justify-content: center; min-width: 2.2rem; height: 2.2rem; padding: 0 0.5rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: var(--zchpc-text); background: #ffffff; text-decoration: none; transition: var(--transition);" onmouseover="this.style.borderColor='var(--zchpc-primary)';this.style.color='var(--zchpc-primary)';" onmouseout="this.style.borderColor='var(--zchpc-border)';this.style.color='var(--zchpc-text)';">
                    {{ $personnel->lastPage() }}
                </a>
            @endif

            {{-- Next Button --}}
            @if ($personnel->hasMorePages())
                <a href="{{ $personnel->nextPageUrl() }}" style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.45rem 0.95rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: var(--zchpc-text); background: #ffffff; text-decoration: none; transition: var(--transition); box-shadow: var(--shadow-sm);" onmouseover="this.style.borderColor='var(--zchpc-primary)';this.style.color='var(--zchpc-primary)';" onmouseout="this.style.borderColor='var(--zchpc-border)';this.style.color='var(--zchpc-text)';">
                    Next <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem;"></i>
                </a>
            @else
                <span style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.45rem 0.95rem; font-size: 0.85rem; font-weight: 500; border-radius: var(--radius-md); border: 1px solid var(--zchpc-border); color: #94a3b8; background: #f8fafc; cursor: not-allowed; user-select: none;">
                    Next <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem;"></i>
                </span>
            @endif
        </div>
    </div>
    @endif
</div>

<script>
// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('importDropdownWrap');
    const dd   = document.getElementById('importDropdown');
    if (wrap && dd && !wrap.contains(e.target)) {
        dd.style.display = 'none';
    }
});
document.getElementById('importDropdown')?.addEventListener('click', function(e){
    e.stopPropagation();
});
// Toggle uses style.display so we need to handle both states
document.querySelector('[onclick*="importDropdown"]')?.addEventListener('click', function(e){
    e.stopPropagation();
    const dd = document.getElementById('importDropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
});
</script>
@endsection


