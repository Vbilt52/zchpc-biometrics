@extends('layouts.app')

@section('title', 'Devices - ZCHPC Access Control')
@section('header', 'Device Management')

@push('styles')
    <link rel="stylesheet" href="/css/devices.css">
@endpush

@section('content')
    <div id="device-dashboard"
         data-statuses-url="{{ route('devices.statuses') }}"
         data-refresh-url="{{ route('devices.refresh.bulk') }}"
         data-refresh-one-template="{{ route('devices.refresh', ['terminal' => '__ID__']) }}"
         data-diagnostics-template="{{ route('devices.diagnostics', ['terminal' => '__ID__']) }}"
         data-reconnect-template="{{ route('devices.reconnect', ['terminal' => '__ID__']) }}"
         data-run-url-template="{{ route('devices.refresh.run', ['run' => '__ID__']) }}"
         data-cancel-url-template="{{ route('devices.refresh.cancel', ['run' => '__ID__']) }}"
         data-active-run="{{ $activeRun?->id }}"
         data-can-manage="{{ auth()->user()->isAdmin() ? '1' : '0' }}"
         data-failure-threshold="{{ $failureThreshold }}">

        <div class="device-toolbar">
            <div>
                <p style="margin:0;color:var(--zchpc-text-muted);font-size:.9rem;">
                    Live connectivity for every registered device. Online means the device answered an authenticated request; cached flags are never used.
                </p>
                @unless($settings->enabled)
                    <p style="margin:.4rem 0 0;color:#b45309;font-size:.82rem;">
                        <i class="fa-solid fa-pause"></i> Automatic monitoring is paused. Refresh actions still work.
                    </p>
                @endunless
            </div>
            <div class="device-actions">
                @can('devices.manage')
                    <button type="button" class="btn btn-primary" id="refresh-all">
                        <i class="fa-solid fa-rotate"></i> Refresh all
                    </button>
                    <button type="button" class="btn btn-outline" id="refresh-offline">
                        <i class="fa-solid fa-plug-circle-xmark"></i> Refresh offline
                    </button>
                    <button type="button" class="btn btn-outline" id="refresh-selected" disabled>
                        <i class="fa-solid fa-list-check"></i> Refresh selected
                    </button>
                @endcan
                <a href="{{ route('devices.activity.index') }}" class="btn btn-outline">
                    <i class="fa-solid fa-clock-rotate-left"></i> Activity log
                </a>
            </div>
        </div>

        @if(session('success'))
            <div style="background: rgba(16,185,129,0.1); color: #047857; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-circle-check"></i> {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div style="background: rgba(239,68,68,0.1); color: #b91c1c; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-circle-exclamation"></i> {{ session('error') }}
            </div>
        @endif

        <div class="device-stats">
            @php
                $cards = [
                    ['key' => 'total', 'label' => 'Registered devices', 'icon' => 'fa-server', 'tone' => 'primary'],
                    ['key' => 'online', 'label' => 'Online', 'icon' => 'fa-circle-check', 'tone' => 'success'],
                    ['key' => 'offline', 'label' => 'Offline', 'icon' => 'fa-plug-circle-xmark', 'tone' => 'danger'],
                    ['key' => 'connecting', 'label' => 'Connecting', 'icon' => 'fa-arrows-rotate', 'tone' => 'primary'],
                    ['key' => 'error', 'label' => 'With errors', 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning'],
                    ['key' => 'attention', 'label' => 'Requiring attention', 'icon' => 'fa-user-gear', 'tone' => 'warning'],
                ];
            @endphp
            @foreach($cards as $card)
                <div class="device-stat">
                    <div class="icon {{ $card['tone'] }}"><i class="fa-solid {{ $card['icon'] }}"></i></div>
                    <div style="min-width:0;">
                        <div class="value" data-stat="{{ $card['key'] }}">{{ $stats[$card['key']] }}</div>
                        <div class="label">{{ $card['label'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($stats['unmonitored'] > 0)
            <p class="device-note" style="margin:-0.5rem 0 1.25rem;">
                {{ $stats['unmonitored'] }} reader(s) have no remote API (standalone/Wiegand) and are not counted as online or offline.
            </p>
        @endif

        <div class="device-filters">
            @php
                $pills = ['all' => 'All', 'online' => 'Online', 'offline' => 'Offline', 'connecting' => 'Connecting', 'error' => 'Error', 'attention' => 'Attention'];
            @endphp
            @foreach($pills as $key => $label)
                <a href="{{ route('devices.index', array_filter(['status' => $key === 'all' ? null : $key, 'search' => $filters['search'] ?: null, 'phase' => $filters['phase']])) }}"
                   class="filter-pill {{ $filters['status'] === $key ? 'active' : '' }}">
                    {{ $label }}
                    @if(isset($stats[$key]))
                        <span class="count" data-stat="{{ $key }}">{{ $stats[$key] }}</span>
                    @endif
                </a>
            @endforeach

            <form method="GET" action="{{ route('devices.index') }}" class="device-search">
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search name, IP, location, model" aria-label="Search devices">
                <select name="phase" aria-label="Filter by phase">
                    <option value="">All phases</option>
                    @foreach($phases as $phase)
                        <option value="{{ $phase }}" @selected($filters['phase'] === $phase)>{{ $phase }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary btn-xs"><i class="fa-solid fa-filter"></i> Apply</button>
                @if($filters['search'] !== '' || $filters['phase'] !== null || $filters['status'] !== 'all')
                    <a href="{{ route('devices.index') }}" class="btn btn-outline btn-xs">Clear</a>
                @endif
            </form>
        </div>

        <div class="device-progress" id="refresh-progress" @if($activeRun === null) hidden @endif>
            <div class="device-progress-header">
                <strong id="progress-title">Refreshing devices…</strong>
                <span id="progress-counts">{{ $activeRun ? "{$activeRun->completed} of {$activeRun->total} completed" : '' }}</span>
            </div>
            <div class="device-progress-bar"><span id="progress-bar"></span></div>
            <div class="device-progress-foot">
                <span id="progress-message">Each device is updated here as its result arrives. Door access is not affected.</span>
                <span>
                    @can('devices.manage')
                        <button type="button" class="btn btn-outline btn-xs" id="cancel-refresh" @if($activeRun === null) hidden @endif>
                            <i class="fa-solid fa-ban"></i> Cancel pending checks
                        </button>
                    @endcan
                </span>
            </div>
        </div>

        @if($lastRun && $activeRun === null)
            <p class="device-note" style="margin:-0.6rem 0 1rem;">
                <i class="fa-solid fa-clock"></i>
                Last refresh run ({{ str_replace('_', ' ', $lastRun->scope) }}) {{ $lastRun->status }}:
                {{ $lastRun->message ?: 'no summary recorded' }}
                @if($lastRun->finished_at)
                    — {{ $lastRun->finished_at->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i:s') }}
                @endif
            </p>
        @endif

        @can('devices.manage')
            <details class="device-settings">
                <summary><i class="fa-solid fa-sliders"></i> Monitoring settings</summary>
                <form method="POST" action="{{ route('devices.monitor.settings') }}">
                    @csrf
                    <label class="checkbox-label">
                        <input type="checkbox" name="enabled" value="1" @checked($settings->enabled)>
                        Automatic background monitoring
                    </label>
                    <label>
                        Check interval (seconds)
                        <input type="number" name="interval_seconds" min="{{ config('devices.monitor.min_interval_seconds') }}" max="{{ config('devices.monitor.max_interval_seconds') }}" value="{{ $settings->interval_seconds }}" required>
                    </label>
                    <label>
                        Alert cooldown (minutes)
                        <input type="number" name="alert_cooldown_minutes" min="1" max="1440" value="{{ $settings->alert_cooldown_minutes }}" required>
                    </label>
                    <button type="submit" class="btn btn-primary btn-xs"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
                </form>
                <p class="device-note" style="margin:.6rem 0 0;">
                    Recommended intervals: 30 seconds, 1 minute, 5 minutes or 10 minutes. The scheduler wakes every minute and checks each device once its interval has elapsed. Repeat alerts inside the cooldown window are counted on the existing alert instead of creating new ones.
                    @if($settings->last_run_at)
                        Last scheduled run: {{ $settings->last_run_at->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i:s') }}.
                    @endif
                </p>
            </details>
        @endcan

        @if($terminals->isEmpty())
            <div style="text-align:center;padding:4rem 2rem;background:var(--zchpc-surface);border-radius:var(--radius-xl);border:1px solid var(--zchpc-border);color:var(--zchpc-text-muted);">
                <i class="fa-solid fa-server" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.2;"></i>
                @if($stats['total'] === 0)
                    <h3 style="margin-bottom:.5rem;color:var(--zchpc-secondary);">No terminals configured</h3>
                    <p style="margin-bottom:1.5rem;">Add a terminal to start monitoring door controllers.</p>
                    <a href="{{ route('terminals.create') }}" class="btn btn-primary">Add first terminal</a>
                @else
                    <h3 style="margin-bottom:.5rem;color:var(--zchpc-secondary);">No devices match this filter</h3>
                    <p style="margin-bottom:1.5rem;">Clear the search or choose another status filter.</p>
                    <a href="{{ route('devices.index') }}" class="btn btn-outline">Show all devices</a>
                @endif
            </div>
        @else
            <div class="table-card">
                <div class="table-header">
                    <div>
                        <h2>Registered devices</h2>
                        <p style="margin:.3rem 0 0;color:var(--zchpc-text-muted);font-size:.8rem;">
                            Times shown in {{ config('attendance.timezone') }}. <span id="selection-count"></span>
                        </p>
                    </div>
                    <a href="{{ route('devices.alerts.index') }}" class="btn btn-outline btn-xs">
                        <i class="fa-solid fa-bell"></i> Alerts @if($unacknowledgedAlerts > 0)<span class="badge badge-danger" style="margin-left:.35rem;">{{ $unacknowledgedAlerts }}</span>@endif
                    </a>
                </div>
                <div class="table-wrapper">
                    <table class="device-table">
                        <thead>
                            <tr>
                                <th class="check-col">
                                    <input type="checkbox" id="select-all-devices" aria-label="Select all remote-managed devices">
                                </th>
                                <th>Device</th>
                                <th class="hide-md">ID / address</th>
                                <th class="hide-md">Type / model</th>
                                <th>Status</th>
                                <th class="hide-md">Last checked</th>
                                <th>Last successful</th>
                                <th class="hide-md">Response</th>
                                <th>Error / attention</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($terminals as $terminal)
                                <tr data-device-row data-device-id="{{ $terminal->id }}">
                                    <td class="check-col">
                                        @if($terminal->isApiManaged())
                                            <input type="checkbox" value="{{ $terminal->id }}" data-device-select aria-label="Select {{ $terminal->name }}">
                                        @endif
                                    </td>
                                    <td>
                                        <div class="device-name">{{ $terminal->name }}</div>
                                        <div class="device-meta">
                                            {{ $terminal->phase }}
                                            @if($terminal->location) &middot; {{ $terminal->location }} @endif
                                        </div>
                                    </td>
                                    <td class="hide-md">
                                        <div class="device-meta">ID {{ $terminal->id }}</div>
                                        <div class="device-meta" style="font-family:monospace;">{{ $terminal->ip }}:{{ $terminal->port }}</div>
                                    </td>
                                    <td class="hide-md">
                                        <div class="device-meta">{{ $terminal->integrationLabel() }}</div>
                                        <div class="device-meta">{{ $terminal->model ?: ($terminal->isApiManaged() ? 'Model not recorded' : 'Reader') }}</div>
                                    </td>
                                    <td data-cell="status">
                                        <span class="badge badge-{{ $terminal->statusTone() }}">
                                            <i class="fa-solid fa-circle status-dot"></i>{{ $terminal->statusLabel() }}
                                        </span>
                                        @if($terminal->isApiManaged())
                                            <div class="device-meta">{{ $terminal->status_source ? ucfirst($terminal->status_source) . ' check' : 'Never checked' }}</div>
                                        @endif
                                    </td>
                                    <td class="hide-md" data-cell="last_checked">
                                        {{ $terminal->last_checked_at?->copy()->timezone(config('attendance.timezone'))->format('d M H:i:s') ?? 'Never' }}
                                    </td>
                                    <td data-cell="last_seen">
                                        {{ $terminal->last_seen_at?->copy()->timezone(config('attendance.timezone'))->format('d M H:i:s') ?? 'Never' }}
                                    </td>
                                    <td class="hide-md" data-cell="latency">
                                        {{ $terminal->last_latency_ms !== null ? $terminal->last_latency_ms . ' ms' : '—' }}
                                    </td>
                                    <td data-cell="error">
                                        @if($terminal->last_error)
                                            <span class="device-error">{{ $terminal->last_error }}</span>
                                        @elseif($terminal->attentionReason($settings))
                                            <span class="device-attention">{{ $terminal->attentionReason($settings) }}</span>
                                        @elseif(! $terminal->isApiManaged())
                                            <span class="device-note">Local reader; no remote checks.</span>
                                        @else
                                            <span class="device-note">No current error.</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="device-actions-cell">
                                            @if($terminal->isApiManaged())
                                                @can('devices.manage')
                                                    <button type="button" class="btn btn-outline btn-xs" data-action="refresh" data-id="{{ $terminal->id }}">
                                                        <i class="fa-solid fa-rotate"></i> Refresh
                                                    </button>
                                                @endcan
                                                @can('devices.diagnostics')
                                                    <button type="button" class="btn btn-outline btn-xs" data-action="diagnostics" data-id="{{ $terminal->id }}" data-name="{{ $terminal->name }}">
                                                        <i class="fa-solid fa-stethoscope"></i> Diagnose
                                                    </button>
                                                @endcan
                                                @can('devices.manage')
                                                    <button type="button" class="btn btn-outline btn-xs" data-action="reconnect" data-id="{{ $terminal->id }}" data-name="{{ $terminal->name }}">
                                                        <i class="fa-solid fa-plug-circle-bolt"></i> Reconnect
                                                    </button>
                                                @endcan
                                                <span data-door-controls @if(! $terminal->isOnline()) hidden @endif style="display:contents;">
                                                    <form action="{{ route('devices.door.open', ['terminalName' => $terminal->name, 'doorNo' => 1]) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" class="btn btn-xs" style="background:rgba(16,185,129,.1);color:#047857;border:1px solid rgba(16,185,129,.4);">
                                                            <i class="fa-solid fa-door-open"></i> Unlock
                                                        </button>
                                                    </form>
                                                    <form action="{{ route('devices.door.close', ['terminalName' => $terminal->name, 'doorNo' => 1]) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" class="btn btn-xs" style="background:rgba(239,68,68,.08);color:#b91c1c;border:1px solid rgba(239,68,68,.3);">
                                                            <i class="fa-solid fa-lock"></i> Lock
                                                        </button>
                                                    </form>
                                                </span>
                                            @else
                                                <span class="device-note">No remote API.</span>
                                            @endif
                                            @can('devices.manage')
                                                <a href="{{ route('terminals.edit', $terminal) }}" class="btn btn-outline btn-xs" title="Edit terminal" aria-label="Edit {{ $terminal->name }}">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                            @endcan
                                            <a href="{{ route('personnel.index', ['terminal_id' => $terminal->id]) }}" class="btn btn-outline btn-xs" title="Assigned people" aria-label="Assigned people">
                                                <i class="fa-solid fa-users"></i>
                                            </a>
                                            <a href="{{ route('events.failed', ['terminal_id' => $terminal->id]) }}" class="btn btn-outline btn-xs" title="Failed attempts" aria-label="Failed attempts">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <p class="device-note" style="margin-top:1.25rem;line-height:1.5;">
            <i class="fa-solid fa-shield-halved"></i>
            Refresh only reads device information. Reconnect clears this application's saved connection and authenticates again. Neither changes door permissions, schedules or credentials, and no remote restart is offered because the installed device API has no documented, authenticated restart call.
        </p>

        <div class="device-modal" id="device-diagnostics-modal" hidden>
            <div class="device-modal-card" role="dialog" aria-modal="true" aria-labelledby="diagnostics-title">
                <div class="device-modal-head">
                    <div>
                        <h2 id="diagnostics-title">Device diagnostics</h2>
                        <p id="diagnostics-subtitle"></p>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" id="diagnostics-close" aria-label="Close diagnostics">
                        <i class="fa-solid fa-xmark"></i> Close
                    </button>
                </div>
                <div id="diagnostics-body">
                    <p class="device-note"><i class="fa-solid fa-spinner fa-spin"></i> Running read-only diagnostics…</p>
                </div>
            </div>
        </div>

        <div class="device-toasts" id="device-toasts" aria-live="polite"></div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var root = document.getElementById('device-dashboard');
            if (!root) {
                return;
            }

            var csrf = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = csrf ? csrf.content : '';
            var canManage = root.dataset.canManage === '1';
            var activeRunId = root.dataset.activeRun ? Number(root.dataset.activeRun) : null;
            var pollTimer = null;
            var progress = document.getElementById('refresh-progress');

            function urlFrom(template, id) {
                return template.replace('__ID__', String(id));
            }

            function request(url, options) {
                options = options || {};
                return fetch(url, {
                    method: options.method || 'GET',
                    headers: Object.assign({
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken
                    }, options.headers || {}),
                    body: options.body
                }).then(function (response) {
                    return response.json().catch(function () {
                        return null;
                    }).then(function (data) {
                        if (!response.ok) {
                            var message = (data && data.message) || 'Request failed (' + response.status + ').';
                            if (data && data.errors) {
                                message = Object.keys(data.errors).map(function (key) {
                                    return data.errors[key].join(' ');
                                }).join(' ');
                            }
                            var error = new Error(message);
                            error.data = data;
                            throw error;
                        }
                        return data;
                    });
                });
            }

            function toast(message, tone) {
                var container = document.getElementById('device-toasts');
                if (!container || !message) {
                    return;
                }
                var el = document.createElement('div');
                el.className = 'device-toast ' + (tone || 'info');
                el.textContent = message;
                container.appendChild(el);
                setTimeout(function () {
                    el.remove();
                }, 7000);
            }

            function rowFor(id) {
                return document.querySelector('[data-device-row][data-device-id="' + id + '"]');
            }

            function setStatusBadge(row, label, tone) {
                var cell = row.querySelector('[data-cell="status"]');
                if (!cell) {
                    return;
                }
                var badge = cell.querySelector('.badge');
                if (!badge) {
                    return;
                }
                badge.className = 'badge badge-' + tone;
                badge.textContent = '';
                var dot = document.createElement('i');
                dot.className = 'fa-solid fa-circle status-dot';
                badge.appendChild(dot);
                badge.appendChild(document.createTextNode(label));
            }

            function applyDevice(device) {
                var row = rowFor(device.id);
                if (!row) {
                    return;
                }
                row.classList.remove('device-row-checking');
                setStatusBadge(row, device.status_label, device.status_tone);
                var checked = row.querySelector('[data-cell="last_checked"]');
                if (checked) {
                    checked.textContent = device.last_checked;
                }
                var seen = row.querySelector('[data-cell="last_seen"]');
                if (seen) {
                    seen.textContent = device.last_seen;
                }
                var doorControls = row.querySelector('[data-door-controls]');
                if (doorControls) {
                    // Door controls are only offered while an authenticated check
                    // confirms the device; a device that just went offline must
                    // not keep stale unlock buttons.
                    doorControls.hidden = device.status !== 'online';
                }
                var latency = row.querySelector('[data-cell="latency"]');
                if (latency) {
                    latency.textContent = device.latency_ms === null ? '—' : device.latency_ms + ' ms';
                }
                var error = row.querySelector('[data-cell="error"]');
                if (error) {
                    error.textContent = '';
                    var text = device.error || device.attention_reason || (device.monitored ? 'No current error.' : 'Local reader; no remote checks.');
                    var span = document.createElement('span');
                    span.className = device.error ? 'device-error' : 'device-note';
                    span.textContent = text;
                    error.appendChild(span);
                }
            }

            function setRowChecking(row) {
                if (!row) {
                    return;
                }
                row.classList.add('device-row-checking');
                setStatusBadge(row, 'Checking', 'primary');
            }

            function applyStats(stats) {
                if (!stats) {
                    return;
                }
                Object.keys(stats).forEach(function (key) {
                    document.querySelectorAll('[data-stat="' + key + '"]').forEach(function (el) {
                        el.textContent = stats[key];
                    });
                });
            }

            function selectedIds() {
                return Array.prototype.slice.call(document.querySelectorAll('[data-device-select]:checked')).map(function (input) {
                    return Number(input.value);
                });
            }

            function updateSelection() {
                var count = selectedIds().length;
                var label = document.getElementById('selection-count');
                if (label) {
                    label.textContent = count > 0 ? count + ' selected for a bulk refresh.' : '';
                }
                var bulk = document.getElementById('refresh-selected');
                if (bulk) {
                    bulk.disabled = count === 0;
                }
            }

            var selectAll = document.getElementById('select-all-devices');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('[data-device-select]').forEach(function (input) {
                        input.checked = selectAll.checked;
                    });
                    updateSelection();
                });
            }
            document.querySelectorAll('[data-device-select]').forEach(function (input) {
                input.addEventListener('change', updateSelection);
            });

            function renderRun(run, items) {
                if (!progress || !run) {
                    return;
                }
                progress.hidden = false;
                var finished = run.completed + run.cancelled;
                var percent = run.total > 0 ? Math.round((finished / run.total) * 100) : 0;
                var counts = document.getElementById('progress-counts');
                var bar = document.getElementById('progress-bar');
                var message = document.getElementById('progress-message');
                var cancel = document.getElementById('cancel-refresh');
                if (counts) {
                    counts.textContent = run.completed + ' of ' + run.total + ' completed' + (run.failed > 0 ? ', ' + run.failed + ' failed' : '') + (run.cancelled > 0 ? ', ' + run.cancelled + ' cancelled' : '');
                }
                if (bar) {
                    bar.style.width = percent + '%';
                }
                if (message) {
                    if (run.status === 'queued' && run.age_seconds > 60) {
                        // A queued run with no progress usually means no queue
                        // worker is running, not that a device is slow.
                        message.textContent = 'This run is queued but has not started. Check that a queue worker (php artisan queue:work) is running, or cancel the run.';
                    } else {
                        message.textContent = run.message || 'Probing devices with bounded concurrency; door access is not affected.';
                    }
                }
                if (cancel) {
                    cancel.hidden = !(run.status === 'queued' || run.status === 'running');
                }
                (items || []).forEach(function (item) {
                    var row = rowFor(item.terminal_id);
                    if (!row) {
                        return;
                    }
                    if (item.status === 'checking' || item.status === 'pending') {
                        setRowChecking(row);
                    } else if (item.status === 'online') {
                        setStatusBadge(row, 'Online', 'success');
                        row.classList.remove('device-row-checking');
                    } else if (item.status === 'offline') {
                        setStatusBadge(row, 'Offline', 'danger');
                        row.classList.remove('device-row-checking');
                    } else if (item.status === 'error') {
                        setStatusBadge(row, 'Error', 'warning');
                        row.classList.remove('device-row-checking');
                    } else if (item.status === 'cancelled') {
                        row.classList.remove('device-row-checking');
                    }
                });
            }

            function pollRun() {
                if (!activeRunId) {
                    return;
                }
                clearTimeout(pollTimer);
                request(urlFrom(root.dataset.runUrlTemplate, activeRunId)).then(function (data) {
                    renderRun(data.run, data.items);
                    applyStats(data.stats);
                    if (data.run && (data.run.status === 'queued' || data.run.status === 'running')) {
                        pollTimer = setTimeout(pollRun, 1500);
                    } else {
                        var finishedRun = data.run || {};
                        activeRunId = null;
                        toast(finishedRun.message || 'Refresh finished.', finishedRun.status === 'completed' ? 'success' : 'info');
                        refreshStatuses();
                        setTimeout(function () {
                            if (progress) {
                                progress.hidden = true;
                            }
                        }, 6000);
                    }
                }).catch(function (error) {
                    toast(error.message, 'error');
                    pollTimer = setTimeout(pollRun, 3000);
                });
            }

            function startRun(payload) {
                return request(root.dataset.refreshUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                }).then(function (data) {
                    toast(data.message, 'success');
                    if (data.run) {
                        activeRunId = data.run.id;
                        renderRun(data.run, []);
                        pollRun();
                    }
                }).catch(function (error) {
                    toast(error.message, 'error');
                    if (error.data && error.data.run) {
                        activeRunId = error.data.run.id;
                        renderRun(error.data.run, []);
                        pollRun();
                    }
                });
            }

            var refreshAll = document.getElementById('refresh-all');
            if (refreshAll) {
                refreshAll.addEventListener('click', function () {
                    startRun({scope: 'all'});
                });
            }

            var refreshOffline = document.getElementById('refresh-offline');
            if (refreshOffline) {
                refreshOffline.addEventListener('click', function () {
                    startRun({scope: 'offline'});
                });
            }

            var refreshSelected = document.getElementById('refresh-selected');
            if (refreshSelected) {
                refreshSelected.addEventListener('click', function () {
                    var ids = selectedIds();
                    if (ids.length === 0) {
                        toast('Select at least one device first.', 'error');
                        return;
                    }
                    startRun({scope: 'selected', ids: ids});
                });
            }

            var cancelButton = document.getElementById('cancel-refresh');
            if (cancelButton) {
                cancelButton.addEventListener('click', function () {
                    if (!activeRunId) {
                        return;
                    }
                    cancelButton.disabled = true;
                    request(urlFrom(root.dataset.cancelUrlTemplate, activeRunId), {method: 'POST'}).then(function (data) {
                        toast(data.message, 'info');
                        if (data.run) {
                            renderRun(data.run, []);
                        }
                    }).catch(function (error) {
                        toast(error.message, 'error');
                    }).then(function () {
                        cancelButton.disabled = false;
                    });
                });
            }

            document.querySelectorAll('[data-action="refresh"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!canManage) {
                        return;
                    }
                    var row = button.closest('[data-device-row]');
                    button.disabled = true;
                    setRowChecking(row);
                    request(urlFrom(root.dataset.refreshOneTemplate, button.dataset.id), {method: 'POST'}).then(function (data) {
                        applyDevice(data.device);
                        applyStats(data.stats);
                        toast(data.message, data.ok ? 'success' : 'error');
                    }).catch(function (error) {
                        row.classList.remove('device-row-checking');
                        toast(error.message, 'error');
                        refreshStatuses();
                    }).then(function () {
                        button.disabled = false;
                    });
                });
            });

            document.querySelectorAll('[data-action="reconnect"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!canManage) {
                        return;
                    }
                    var confirmed = window.confirm('Reconnect to ' + button.dataset.name + '?\n\nThis clears the saved connection and authenticates again. The device is not restarted and no access permissions change.');
                    if (!confirmed) {
                        return;
                    }
                    button.disabled = true;
                    request(urlFrom(root.dataset.reconnectTemplate, button.dataset.id), {method: 'POST'}).then(function (data) {
                        applyDevice(data.device);
                        toast(data.message, data.ok ? 'success' : 'error');
                    }).catch(function (error) {
                        toast(error.message, 'error');
                    }).then(function () {
                        button.disabled = false;
                    });
                });
            });

            function diagRow(label, value) {
                var row = document.createElement('div');
                row.className = 'diag-row';
                var left = document.createElement('span');
                left.textContent = label;
                var right = document.createElement('span');
                right.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
                row.appendChild(left);
                row.appendChild(right);
                return row;
            }

            function diagSection(title) {
                var section = document.createElement('section');
                section.className = 'diag-section';
                var heading = document.createElement('h3');
                heading.textContent = title;
                section.appendChild(heading);
                return section;
            }

            function renderDiagnostics(report) {
                var subtitle = document.getElementById('diagnostics-subtitle');
                var body = document.getElementById('diagnostics-body');
                if (!body) {
                    return;
                }
                body.textContent = '';
                if (subtitle) {
                    subtitle.textContent = [report.device.name, report.device.type, report.device.address, 'generated ' + report.generated_at].join(' · ');
                }

                var checks = diagSection('Connectivity checks');
                checks.appendChild(diagRow('Network reachability', report.network.tested ? (report.network.reachable ? 'Reachable' : 'Unreachable') + ' — ' + report.network.detail : report.network.detail));
                checks.appendChild(diagRow('Device protocol read', report.protocol.tested ? (report.protocol.ok ? 'Succeeded' : 'Failed') + ' — ' + report.protocol.detail : report.protocol.detail));
                var authLabels = {
                    'accepted': 'Accepted',
                    'rejected': 'Rejected',
                    'not-verified': 'Not verified',
                    'not-tested': 'Not tested'
                };
                checks.appendChild(diagRow('Authentication', (authLabels[report.authentication.status] || report.authentication.status) + ' — ' + report.authentication.detail));
                if (report.network.latency_ms !== null && report.network.latency_ms !== undefined) {
                    checks.appendChild(diagRow('Network response time', report.network.latency_ms + ' ms'));
                }
                body.appendChild(checks);

                var history = diagSection('Connection history');
                history.appendChild(diagRow('Last response time', report.history.last_response_ms === null ? null : report.history.last_response_ms + ' ms'));
                history.appendChild(diagRow('Last checked', report.history.last_checked));
                history.appendChild(diagRow('Last successful connection', report.history.last_successful_connection));
                history.appendChild(diagRow('Failed connection attempts (consecutive)', report.history.failed_attempts));
                history.appendChild(diagRow('Last error', report.history.last_error));
                history.appendChild(diagRow('Last reconnection', report.history.last_reconnect));
                body.appendChild(history);

                var sync = diagSection('Gateway, controller and synchronization');
                sync.appendChild(diagRow('Gateway / controller', report.gateway.detail));
                sync.appendChild(diagRow('Access-log collection', report.sync.detail));
                sync.appendChild(diagRow('Last successful collection', report.sync.last_success));
                if (report.sync.error) {
                    sync.appendChild(diagRow('Collection error', report.sync.error));
                }
                body.appendChild(sync);

                var infoKeys = Object.keys(report.device_info || {});
                if (infoKeys.length > 0 || (report.device_clock && report.device_clock.reported_local_time)) {
                    var info = diagSection('Device-reported information');
                    infoKeys.forEach(function (key) {
                        info.appendChild(diagRow(key, report.device_info[key]));
                    });
                    if (report.device_clock && report.device_clock.reported_local_time) {
                        info.appendChild(diagRow('Device clock', report.device_clock.reported_local_time));
                    }
                    info.appendChild(diagRow('Clock check', report.device_clock.note));
                    body.appendChild(info);
                }

                var errors = diagSection('Recent errors');
                if (!report.recent_errors || report.recent_errors.length === 0) {
                    var none = document.createElement('p');
                    none.className = 'device-note';
                    none.textContent = 'No failed device operations have been recorded.';
                    errors.appendChild(none);
                } else {
                    var list = document.createElement('ul');
                    list.className = 'diag-list';
                    report.recent_errors.forEach(function (entry) {
                        var item = document.createElement('li');
                        item.textContent = entry.at + ' — ' + entry.action + ': ' + (entry.message || 'no detail');
                        list.appendChild(item);
                    });
                    errors.appendChild(list);
                }
                body.appendChild(errors);

                var advice = diagSection('Troubleshooting recommendations');
                var adviceList = document.createElement('ul');
                adviceList.className = 'diag-list';
                (report.recommendations || []).forEach(function (line) {
                    var item = document.createElement('li');
                    item.textContent = line;
                    adviceList.appendChild(item);
                });
                advice.appendChild(adviceList);
                body.appendChild(advice);
            }

            var modal = document.getElementById('device-diagnostics-modal');
            var closeModal = document.getElementById('diagnostics-close');
            if (closeModal) {
                closeModal.addEventListener('click', function () {
                    modal.hidden = true;
                });
            }
            if (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        modal.hidden = true;
                    }
                });
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && !modal.hidden) {
                        modal.hidden = true;
                    }
                });
            }

            document.querySelectorAll('[data-action="diagnostics"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    button.disabled = true;
                    if (modal) {
                        modal.hidden = false;
                    }
                    request(urlFrom(root.dataset.diagnosticsTemplate, button.dataset.id)).then(function (report) {
                        renderDiagnostics(report);
                    }).catch(function (error) {
                        var body = document.getElementById('diagnostics-body');
                        if (body) {
                            body.textContent = '';
                            var message = document.createElement('p');
                            message.className = 'device-error';
                            message.textContent = error.message;
                            body.appendChild(message);
                        }
                        toast(error.message, 'error');
                    }).then(function () {
                        button.disabled = false;
                    });
                });
            });

            function refreshStatuses() {
                return request(root.dataset.statusesUrl).then(function (data) {
                    applyStats(data.stats);
                    (data.devices || []).forEach(applyDevice);
                }).catch(function () {
                    // A transient poll failure must not interrupt the page.
                });
            }

            if (activeRunId) {
                pollRun();
            }

            setInterval(function () {
                if (!document.hidden && !activeRunId) {
                    refreshStatuses();
                }
            }, 20000);
        })();
    </script>
@endpush
