@extends('layouts.app')

@section('title', $personnel->name . ' - Users & Access')
@section('header', 'User profile')

@section('content')
@include('access.partials.alerts')

@php
    $tz = config('attendance.timezone');
    $fingerprints = $personnel->confirmedFingerprints();
    $pending = $personnel->pendingSyncs;
    $canManage = auth()->user()->can('access.manage');
@endphp

<div class="table-card" style="padding:1.5rem;margin-bottom:1.25rem;">
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
        <div style="display:flex;gap:1rem;align-items:flex-start;">
            @if($personnel->photo_path)
                <img src="{{ $personnel->photoUrl() }}?v={{ $personnel->updated_at?->timestamp }}" alt="Photo of {{ $personnel->name }}"
                     style="width:76px;height:76px;border-radius:50%;object-fit:cover;border:1px solid var(--zchpc-border);">
            @else
                <span style="width:76px;height:76px;border-radius:50%;display:grid;place-items:center;background:rgba(0,74,134,.1);color:var(--zchpc-primary);font-weight:700;font-size:1.4rem;">
                    {{ $personnel->initials() }}
                </span>
            @endif
            <div>
                <h2 style="margin:0;">{{ $personnel->name }}</h2>
                <div class="hint" style="margin-top:.2rem;"><code>{{ $personnel->employee_no }}</code> · {{ $personnel->accessGroup?->name ?? $personnel->department ?? 'No department' }}</div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.5rem;">
                    <span class="badge badge-{{ $personnel->accessStateTone() === 'muted' ? 'muted' : $personnel->accessStateTone() }}">{{ $personnel->accessStateLabel() }}</span>
                    <span class="badge {{ $personnel->roleBadgeClass() }}">{{ $personnel->roleLabel() }}</span>
                    @if($fingerprints->isNotEmpty())
                        <span class="badge badge-success"><i class="fa-solid fa-fingerprint"></i> {{ $fingerprints->count() }} finger(s)</span>
                    @else
                        <span class="badge badge-muted">No fingerprint</span>
                    @endif
                </div>
            </div>
        </div>

        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="{{ route('access.index') }}" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> All users</a>
            @if($canManage)
                @if($personnel->isDraft())
                    <form method="POST" action="{{ route('access.users.resume', $personnel) }}" style="margin:0;">
                        @csrf
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-play"></i> Resume onboarding</button>
                    </form>
                    <form method="POST" action="{{ route('access.users.discard', $personnel) }}" style="margin:0;"
                          onsubmit="return confirm('Discard this draft? A device identity created for enrollment is removed first.')">
                        @csrf @method('DELETE')
                        <input type="hidden" name="confirm" value="1">
                        <button class="btn btn-outline" type="submit" style="color:var(--danger);">Discard draft</button>
                    </form>
                @else
                    <a href="{{ route('access.users.edit', $personnel) }}" class="btn btn-outline"><i class="fa-solid fa-pen"></i> Edit details</a>
                    <a href="{{ route('access.users.enroll', $personnel) }}" class="btn btn-primary"><i class="fa-solid fa-fingerprint"></i> Enroll fingerprint</a>
                @endif
            @endif
        </div>
    </div>

    <div class="form-grid" style="margin-top:1.25rem;">
        <div>
            <div class="hint">Access window</div>
            <div style="font-size:.86rem;">
                {{ $personnel->begin_time?->timezone($tz)->format('d M Y H:i') ?? '—' }}
                to {{ $personnel->end_time?->timezone($tz)->format('d M Y H:i') ?? '—' }}
            </div>
        </div>
        <div>
            <div class="hint">Phone</div>
            <div style="font-size:.86rem;">{{ $personnel->phone_number ?: '—' }}</div>
        </div>
        <div>
            <div class="hint">Email</div>
            <div style="font-size:.86rem;">{{ $personnel->email ?: '—' }}</div>
        </div>
        <div>
            <div class="hint">Added</div>
            <div style="font-size:.86rem;">{{ $personnel->added_at?->format('d M Y') ?? '—' }}</div>
        </div>
    </div>

    @if($personnel->notes)
        <div style="margin-top:1rem;padding-top:.85rem;border-top:1px solid var(--zchpc-border);">
            <div class="hint">Notes</div>
            <div style="font-size:.86rem;white-space:pre-line;">{{ $personnel->notes }}</div>
        </div>
    @endif
</div>

@if($personnel->isDraft())
    <div class="notice notice-warning">
        <i class="fa-solid fa-circle-half-stroke" style="margin-top:.1rem;"></i>
        <div>
            <strong>Onboarding is not finished.</strong>
            This person has no door access. Resume the setup to choose a device, capture a fingerprint and grant doors.
        </div>
    </div>
@elseif($personnel->isSuspended())
    <div class="notice notice-warning">
        <i class="fa-solid fa-circle-pause" style="margin-top:.1rem;"></i>
        <div>
            <strong>Access is suspended.</strong>
            Every controller is asked to block this person. Door grants and fingerprint credentials are retained so a
            controlled reactivation can restore only the doors still listed below.
        </div>
    </div>
@endif

@if($pending->isNotEmpty())
    <div class="notice notice-warning">
        <i class="fa-solid fa-rotate" style="margin-top:.1rem;"></i>
        <div>
            <strong>{{ $pending->count() }} controller write(s) pending.</strong>
            Until a controller confirms, it may still enforce its previously stored permissions.
            <a href="{{ route('access.sync.index') }}" style="font-weight:600;color:inherit;text-decoration:underline;">Open synchronization</a>.
        </div>
    </div>
@endif

<div class="access-layout" style="margin-bottom:1.25rem;">
    <div class="table-card" style="padding:1.25rem;">
        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;align-items:center;margin-bottom:.6rem;">
            <h3 style="margin:0;font-size:.95rem;">Biometric enrollment</h3>
            <a href="{{ route('access.users.enroll', $personnel) }}" class="btn btn-outline" style="padding:.3rem .6rem;font-size:.75rem;">Manage</a>
        </div>

        @forelse($fingerprints as $fingerprint)
            <div style="border-bottom:1px solid var(--zchpc-border);padding:.55rem 0;">
                <div style="display:flex;justify-content:space-between;gap:.5rem;">
                    <span style="font-size:.86rem;"><i class="fa-solid fa-fingerprint" style="color:var(--success);"></i> {{ $fingerprint->fingerLabelText() }}</span>
                    <span class="badge badge-success">Confirmed</span>
                </div>
                <div class="hint">{{ $fingerprint->terminal_name }} · {{ $fingerprint->finished_at?->format('d M Y H:i') }}</div>
            </div>
        @empty
            <p class="hint">No confirmed fingerprint. Access currently depends on other credentials or administrator decisions.</p>
        @endforelse

        <p class="hint" style="margin-top:.75rem;">
            Fingerprint templates stay on the terminal. This application stores only the device verdict and slot number.
            A template is not copied between devices: enroll again on every other terminal where the person needs
            fingerprint access. Door permissions are independent and can be synchronized.
        </p>
    </div>

    <div class="table-card">
        <div class="table-header" style="padding-bottom:.5rem;">
            <h3 style="margin:0;font-size:.95rem;">Controller deployment</h3>
            @if($canManage && ! $personnel->isDraft())
                <form method="POST" action="{{ route('access.users.sync', $personnel) }}" style="margin:0;">
                    @csrf
                    <button class="btn btn-outline" style="padding:.3rem .6rem;font-size:.75rem;" type="submit">
                        <i class="fa-solid fa-rotate"></i> Sync now
                    </button>
                </form>
            @endif
        </div>
        @include('access.partials.controller-states', ['controllerStates' => $controllerStates])
    </div>
</div>

<div class="table-card" style="margin-bottom:1.25rem;">
    <div class="table-header">
        <h3 style="margin:0;font-size:.95rem;">Door permissions</h3>
        <span class="hint">{{ $grantedDoors->count() }} granted · {{ $grantedDoors->where('is_active', true)->count() }} in service</span>
    </div>

    @if($grantedDoors->isEmpty())
        <div style="padding:1rem 1.25rem;">
            <p class="hint" style="margin:0;">No door access has been granted. This is the least-privilege default for every new person.</p>
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Door</th><th>Area</th><th>Controller</th><th>Granted</th><th>Expires</th><th>State</th></tr></thead>
                <tbody>
                @foreach($grantedDoors as $door)
                    <tr>
                        <td style="font-weight:600;">{{ $door->name }}</td>
                        <td>{{ $door->areaLabel() }}</td>
                        <td>{{ $door->controllerLabel() }}</td>
                        <td class="hint">{{ $door->pivot->granted_at ? \Illuminate\Support\Carbon::parse($door->pivot->granted_at)->format('d M Y') : '—' }}</td>
                        <td class="hint">{{ $door->pivot->expires_at ? \Illuminate\Support\Carbon::parse($door->pivot->expires_at)->format('d M Y') : 'Permanent' }}</td>
                        <td>
                            @if($door->pivot->expires_at && \Illuminate\Support\Carbon::parse($door->pivot->expires_at)->isPast())
                                <span class="badge badge-danger">Expired</span>
                            @elseif($door->is_active)
                                <span class="badge badge-success">Configured</span>
                            @else
                                <span class="badge badge-muted">Door out of service</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($canManage && ! $personnel->isDraft())
        <div style="padding:0 1.25rem 1.25rem;">
            @if($grantedDoors->isNotEmpty())
                <form method="POST" action="{{ route('access.users.doors.revoke', $personnel) }}" style="margin-top:.75rem;"
                      onsubmit="return confirm('Revoke the selected doors? Unrelated doors stay granted.')">
                    @csrf
                    <div class="form-label">Revoke specific doors</div>
                    <div style="display:flex;flex-direction:column;gap:.25rem;margin-bottom:.6rem;">
                        @foreach($grantedDoors as $door)
                            <label class="door-option" style="padding:.15rem .25rem;">
                                <input type="checkbox" name="door_ids[]" value="{{ $door->id }}">
                                <span>{{ $door->name }} <small>{{ $door->controllerLabel() }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    <label style="display:flex;gap:.5rem;align-items:center;font-size:.8rem;margin-bottom:.5rem;">
                        <input type="checkbox" name="confirm" value="1" required>
                        I confirm this revocation. It does not affect the person's other doors.
                    </label>
                    <button class="btn btn-outline" type="submit" style="color:var(--danger);">
                        <i class="fa-solid fa-ban"></i> Revoke selected doors
                    </button>
                </form>
            @endif

            <details style="margin-top:1rem;">
                <summary class="btn btn-primary" style="display:inline-flex;cursor:pointer;">Grant additional doors</summary>
                <form method="POST" action="{{ route('access.users.doors.grant', $personnel) }}" id="grant-doors-form" style="margin-top:1rem;">
                    @csrf
                    @if($accessGroups->isNotEmpty())
                        <div style="margin-bottom:.75rem;">
                            <span class="form-label">Apply a predefined access group</span>
                            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                @foreach($accessGroups as $group)
                                    @if($group->doors->isNotEmpty())
                                        <button type="button" class="btn btn-outline js-apply-group"
                                                data-door-ids="{{ $group->doors->pluck('id')->toJson() }}"
                                                style="padding:.3rem .6rem;font-size:.75rem;">
                                            {{ $group->name }} ({{ $group->doors->count() }})
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @include('access.partials.door-tree', ['doors' => $availableDoors, 'selectedDoorIds' => []])
                    @error('door_ids')<p class="form-error">{{ $message }}</p>@enderror

                    <div class="form-grid" style="margin-top:.75rem;">
                        <div class="form-field">
                            <label class="form-label" for="expires_at">Expires (optional)</label>
                            <input class="form-input" id="expires_at" type="datetime-local" name="expires_at">
                            <p class="hint">Leave empty for permanent access within the person's access window.</p>
                        </div>
                    </div>

                    <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.8rem;margin-bottom:.6rem;">
                        <input type="checkbox" name="confirm" value="1" required style="margin-top:.15rem;">
                        I confirm these doors. Only the selected doors will be written; every other door remains denied.
                    </label>

                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-door-closed"></i> Save &amp; apply access</button>
                </form>
            </details>
        </div>
    @endif
</div>

<div class="access-layout">
    <div class="table-card">
        <div class="table-header"><h3 style="margin:0;font-size:.95rem;">Recent access history</h3><span class="hint">Last 15 events</span></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Time</th><th>Door / terminal</th><th>Result</th><th>Method</th></tr></thead>
                <tbody>
                @forelse($history as $event)
                    <tr>
                        <td class="hint">{{ $event->occurred_at?->format('d M Y H:i:s') }}</td>
                        <td>{{ $event->terminal?->name ?? 'Unknown terminal' }}</td>
                        <td><span class="badge badge-{{ $event->isFailedAttempt() ? 'danger' : 'success' }}">{{ $event->outcomeLabel() }}</span></td>
                        <td class="hint">{{ $event->methodLabel() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="hint" style="text-align:center;padding:1.25rem;">No access events recorded for this employee ID.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        @if($canManage && $audits->isNotEmpty())
            <div class="table-card" style="margin-bottom:1.25rem;">
                <div class="table-header"><h3 style="margin:0;font-size:.95rem;">Access audit trail</h3><a href="{{ route('access.audit.index', ['search' => $personnel->name]) }}" class="hint">Full log</a></div>
                <div style="padding:.75rem 1.25rem;">
                    @foreach($audits as $entry)
                        <div style="border-bottom:1px solid var(--zchpc-border);padding:.45rem 0;">
                            <div style="display:flex;justify-content:space-between;gap:.5rem;">
                                <span style="font-size:.8rem;font-weight:600;">{{ $entry->actionLabel() }}</span>
                                <span class="badge badge-{{ $entry->tone() === 'muted' ? 'muted' : $entry->tone() }}">{{ $entry->outcome }}</span>
                            </div>
                            <div class="hint">{{ $entry->description }}</div>
                            <div class="hint">{{ $entry->user_name }} · {{ $entry->created_at?->format('d M Y H:i') }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($canManage && ! $personnel->isDraft())
            <div class="table-card" style="padding:1.25rem;border-color:rgba(239,68,68,.25);">
                <h3 style="margin:0 0 .5rem;font-size:.95rem;color:#b91c1c;">Sensitive actions</h3>

                @if($personnel->isSuspended())
                    <form method="POST" action="{{ route('access.users.reactivate', $personnel) }}" onsubmit="return confirm('Reactivate this person? Only doors still granted will be restored.')">
                        @csrf
                        <input type="hidden" name="confirm" value="1">
                        <button class="btn btn-primary" type="submit">Reactivate access</button>
                    </form>
                    <p class="hint">Restores only the doors and access window currently on record.</p>
                @else
                    <form method="POST" action="{{ route('access.users.suspend', $personnel) }}" onsubmit="return confirm('Suspend all access for this person? Door grants are retained.')">
                        @csrf
                        <input type="hidden" name="confirm" value="1">
                        <button class="btn btn-outline" type="submit" style="color:#b45309;border-color:#b45309;">Suspend access</button>
                    </form>
                    <p class="hint">Blocks the person on every controller while keeping their profile and door grants.</p>
                @endif

                <hr style="border:0;border-top:1px solid var(--zchpc-border);margin:1rem 0;">

                <details>
                    <summary style="cursor:pointer;font-size:.82rem;font-weight:600;color:#b91c1c;">Delete this person</summary>
                    <p class="hint" style="margin-top:.4rem;">
                        Every assigned controller must confirm removal before the local record is deleted. If a controller is offline,
                        deletion is refused and nothing is lost. Audit history is retained.
                    </p>
                    <form method="POST" action="{{ route('access.users.destroy', $personnel) }}" onsubmit="return confirm('Delete this person after controller confirmation? This cannot be undone.')">
                        @csrf @method('DELETE')
                        <div class="form-field">
                            <label class="form-label" for="confirm_employee_no">Type <code>{{ $personnel->employee_no }}</code> to confirm</label>
                            <input class="form-input" id="confirm_employee_no" name="confirm_employee_no" required autocomplete="off">
                        </div>
                        <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.8rem;margin-bottom:.5rem;">
                            <input type="checkbox" name="confirm" value="1" required style="margin-top:.15rem;">
                            I understand access will be revoked and the profile deleted.
                        </label>
                        <button class="btn btn-outline" type="submit" style="color:var(--danger);border-color:var(--danger);">Delete person</button>
                    </form>
                </details>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('grant-doors-form');
    if (!form) return;

    form.querySelectorAll('.js-apply-group').forEach((button) => {
        button.addEventListener('click', () => {
            const ids = JSON.parse(button.dataset.doorIds || '[]');
            ids.forEach((id) => {
                const box = form.querySelector('input[name="door_ids[]"][value="' + id + '"]');
                if (box) box.checked = true;
            });
        });
    });
})();
</script>
@endpush
