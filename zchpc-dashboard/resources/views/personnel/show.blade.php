@extends('layouts.app')

@section('title', $personnel->name.' - Personnel Detail')
@section('header', 'Personnel Detail')

@section('content')

@if(session('success'))
<div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.35);border-radius:var(--radius-md);padding:0.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.75rem;">
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

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;">

    {{-- Left: Person Card --}}
    <div style="flex:1;min-width:280px;max-width:360px;">

        {{-- Identity Card --}}
        <div class="table-card animate-fade-in" style="margin-bottom:1.25rem;overflow:hidden;">
            <div style="height:80px;background:linear-gradient(135deg,var(--zchpc-primary),#3b82f6);position:relative;">
                <div style="position:absolute;bottom:-36px;left:1.25rem;">
                    <div class="avatar" style="width:72px;height:72px;font-size:1.75rem;border:3px solid white;box-shadow:0 4px 12px rgba(0,0,0,.15);
                        {{ $personnel->user_type==='blocklist'?'background:var(--danger);':($personnel->user_type==='visitor'?'background:#f59e0b;':'') }}">
                        {{ strtoupper(substr($personnel->name,0,1)) }}
                    </div>
                </div>
            </div>
            <div style="padding:3rem 1.25rem 1.25rem;">
                <div style="font-size:1.25rem;font-weight:700;">{{ $personnel->name }}</div>
                <div style="font-family:monospace;font-size:0.875rem;color:var(--zchpc-primary);margin-bottom:.5rem;">{{ $personnel->employee_no }}</div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <span class="badge {{ $personnel->roleBadgeClass() }}">{{ $personnel->roleLabel() }}</span>
                    @if($personnel->isActive())
                        <span class="badge badge-success">Active</span>
                    @else
                        <span class="badge badge-danger">Inactive</span>
                    @endif
                    @if($personnel->accessGroup?->name ?? $personnel->department)
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
                        $groupName = $personnel->accessGroup?->name ?? $personnel->department;
                        $ds = $deptStyles[$groupName] ?? 'background:rgba(100,116,139,0.12);color:#64748b;';
                        $di = $deptIcons[$groupName] ?? 'fa-users';
                    @endphp
                    <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;{{ $ds }}">
                        <i class="fa-solid {{ $di }}" style="font-size:0.65rem;"></i>
                        {{ $groupName }}
                    </span>
                    @endif
                </div>
                @if($personnel->phone_number)
                <div style="font-size:0.85rem;color:var(--zchpc-text-muted);display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                    <i class="fa-solid fa-phone"></i> {{ $personnel->phone_number }}
                </div>
                @endif
            </div>
        </div>

        {{-- Access Window --}}
        <div class="table-card animate-fade-in delay-100" style="margin-bottom:1.25rem;">
            <div class="table-header"><h2 style="font-size:0.9rem;">Access Window</h2></div>
            <div style="padding:1rem 1.25rem;">
                @if($personnel->begin_time)
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:.4rem 0;border-bottom:1px solid var(--zchpc-border);">
                    <span style="color:var(--zchpc-text-muted);">From</span>
                    <span style="font-weight:500;">{{ $personnel->begin_time->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i') }}</span>
                </div>
                @endif
                @if($personnel->end_time)
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:.4rem 0;">
                    <span style="color:var(--zchpc-text-muted);">Until</span>
                    <span style="font-weight:500;{{ $personnel->end_time->isPast() ? 'color:var(--danger);' : '' }}">
                        {{ $personnel->end_time->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i') }}
                        @if($personnel->end_time->isPast()) <span style="font-size:0.7rem;">(expired)</span> @endif
                    </span>
                </div>
                @endif
            </div>
        </div>

        {{-- Door Rights --}}
        <div class="table-card animate-fade-in delay-200" style="margin-bottom:1.25rem;">
            <div class="table-header"><h2 style="font-size:0.9rem;">Channels within assigned devices</h2></div>
            <div style="padding:1rem 1.25rem;">
                @if($personnel->door_right)
                <div style="display:flex;flex-direction:column;gap:.5rem;">
                    @foreach(explode(',', $personnel->door_right) as $door)
                    @php $doorTitle = 'Device channel '.trim($door); @endphp
                    <div style="padding:.45rem .75rem;background:rgba(0,74,134,.07);color:var(--zchpc-primary);border-radius:var(--radius-md);font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.5rem;">
                        <i class="fa-solid fa-door-open"></i> {{ $doorTitle }}
                    </div>
                    @endforeach
                </div>
                @else
                <span style="color:var(--zchpc-text-muted);font-size:0.85rem;">No door rights assigned.</span>
                @endif
            </div>
        </div>

        {{-- Actions --}}
        <div style="display:flex;flex-direction:column;gap:.6rem;">
            <a href="{{ route('personnel.edit', $personnel) }}" class="btn btn-primary" style="justify-content:center;font-weight:600;">
                <i class="fa-solid fa-pen"></i> Edit Person & Permissions
            </a>
            <form action="{{ route('personnel.destroy', $personnel) }}" method="POST"
                onsubmit="return confirm('Permanently remove {{ addslashes($personnel->name) }} from all terminals and the database?')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-outline" style="width:100%;justify-content:center;color:var(--danger);border-color:var(--danger);">
                    <i class="fa-solid fa-trash"></i> Delete Person
                </button>
            </form>
            <a href="{{ route('personnel.index') }}" class="btn btn-outline" style="justify-content:center;">
                <i class="fa-solid fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    {{-- Right: Sync Status & Biometric Enrollment --}}
    <div style="flex:2;min-width:320px;">
        {{-- Direct Biometric Enrollment Card --}}
        @if($terminals->isNotEmpty())
        @php
            // Split synced terminals into confirmed vs still-pending so the UI is honest about state.
            $enrollableTerminals = $terminals->whereIn('id', $syncedIds);
            // Terminals assigned but sync not yet confirmed (access_allowed=true but sync_status != confirmed).
            $pendingTerminals = $personnel->terminals
                ->filter(fn ($t) => (bool) $t->pivot->access_allowed && $t->pivot->sync_status !== 'confirmed')
                ->pluck('id')->toArray();
        @endphp
        <div class="table-card animate-fade-in" style="margin-bottom:1.5rem;border-left:4px solid #10b981;">
            <div class="table-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2><i class="fa-solid fa-fingerprint" style="margin-right:.5rem;color:#10b981;"></i>Hardware Fingerprint Enrollment</h2>
                @if($enrollableTerminals->isNotEmpty())
                    <span class="badge badge-success" style="font-size:0.75rem;"><i class="fa-solid fa-bolt"></i> Ready</span>
                @else
                    <span class="badge" style="font-size:0.75rem;background:rgba(100,116,139,.12);color:#64748b;"><i class="fa-solid fa-clock"></i> Awaiting assignment</span>
                @endif
            </div>
            <div style="padding:1.25rem;">
                @if($enrollableTerminals->isEmpty())
                    <div style="padding:.85rem 1rem;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius-md);font-size:.85rem;color:#92400e;margin-bottom:1rem;">
                        <i class="fa-solid fa-triangle-exclamation" style="margin-right:.35rem;"></i>
                        <strong>No devices are ready for enrollment.</strong>
                        To enroll a fingerprint you must first assign this person to at least one device and save &mdash;
                        the device must confirm the assignment before enrollment is available.
                        <a href="{{ route('personnel.edit', $personnel) }}" style="color:#92400e;text-decoration:underline;">Edit Person &rarr;</a>
                    </div>
                    @if(count($pendingTerminals) > 0)
                    <p style="font-size:.82rem;color:var(--zchpc-text-muted);margin:0;">
                        <i class="fa-solid fa-spinner fa-spin" style="margin-right:.25rem;"></i>
                        {{ count($pendingTerminals) }} device assignment(s) are pending confirmation. Retry synchronization below to speed this up.
                    </p>
                    @endif
                @else
                    <p style="font-size:0.85rem;color:var(--zchpc-text-muted);margin-bottom:1rem;">
                        Select an assigned, confirmed device to prime its biometric reader for <strong>{{ $personnel->name }}</strong>.
                        When primed, ask {{ $personnel->name }} to place their finger on the reader &mdash; the terminal captures it automatically.
                    </p>
                    <form action="{{ route('personnel.enroll.fingerprint', $personnel) }}" method="POST" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
                        @csrf
                        <div style="flex:1;min-width:180px;">
                            <label style="font-size:0.8rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:0.35rem;">TERMINAL DEVICE</label>
                            <select name="terminal_id" id="enroll-terminal-select" style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);font-size:0.875rem;">
                                @foreach($enrollableTerminals as $t)
                                    <option value="{{ $t->id }}"
                                        @disabled(!$t->isOnline())
                                        style="{{ !$t->isOnline() ? 'color:#9ca3af;' : '' }}">
                                        {{ $t->name }} ({{ $t->ip }})
                                        @if(!$t->isOnline()) &mdash; Offline @endif
                                    </option>
                                @endforeach
                            </select>
                            @if($enrollableTerminals->every(fn($t) => !$t->isOnline()))
                                <small style="color:#b45309;display:block;margin-top:.25rem;"><i class="fa-solid fa-triangle-exclamation"></i> All assigned terminals are currently offline.</small>
                            @endif
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label style="font-size:0.8rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:0.35rem;">FINGERPRINT SLOT</label>
                            <select name="finger_id" style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);font-size:0.875rem;">
                                <option value="1">Slot 1 &mdash; Right Index</option>
                                <option value="2">Slot 2 &mdash; Right Thumb</option>
                                <option value="3">Slot 3 &mdash; Right Middle</option>
                                <option value="4">Slot 4 &mdash; Left Index</option>
                                <option value="5">Slot 5 &mdash; Left Thumb</option>
                                <option value="6">Slot 6 &mdash; Left Middle</option>
                                <option value="7">Slot 7 &mdash; Left Ring</option>
                                <option value="8">Slot 8 &mdash; Right Ring</option>
                                <option value="9">Slot 9 &mdash; Right Little</option>
                                <option value="10">Slot 10 &mdash; Left Little</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"
                            style="background:#059669;border-color:#059669;padding:0.65rem 1.25rem;font-weight:600;white-space:nowrap;"
                            @disabled($enrollableTerminals->every(fn($t) => !$t->isOnline()))>
                            <i class="fa-solid fa-fingerprint"></i> Prime Reader
                        </button>
                    </form>
                @endif
            </div>
        </div>
        @endif

        @include('personnel.access-matrix')

        {{-- Recorded Fingerprints --}}
        @if($terminals->isNotEmpty())
        <div class="table-card animate-fade-in delay-200" style="margin-top:1.5rem;">
            <div class="table-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2><i class="fa-solid fa-fingerprint" style="margin-right:.5rem;color:var(--zchpc-primary);"></i>Device Fingerprints</h2>
            </div>
            <div style="padding:1.25rem;">
                <p style="font-size:0.85rem;color:var(--zchpc-text-muted);margin-bottom:1.25rem;">
                    Fingerprint templates currently recorded on synchronized terminals for this person.
                </p>

                <div style="display:flex;flex-direction:column;gap:.75rem;">
                    @forelse($fingerprintsByTerminal ?? [] as $terminalName => $fpData)
                        <div style="border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.875rem 1rem;background:white;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:{{ $fpData['count'] > 0 ? '.75rem' : '0' }};">
                                <div style="font-weight:500;font-size:.9rem;">
                                    <i class="fa-solid fa-server" style="color:var(--zchpc-text-muted);margin-right:.3rem;"></i>
                                    {{ $terminalName }}
                                </div>
                                <div>
                                    @if(!$fpData['online'])
                                        <span class="badge" style="background:rgba(239,68,68,.1);color:#ef4444;font-size:.7rem;"><i class="fa-solid fa-triangle-exclamation"></i> Offline</span>
                                    @elseif($fpData['count'] > 0)
                                        <span class="badge badge-success" style="font-size:.7rem;">{{ $fpData['count'] }} enrolled</span>
                                    @else
                                        <span class="badge" style="background:rgba(100,116,139,.1);color:#64748b;font-size:.7rem;">None</span>
                                    @endif
                                </div>
                            </div>
                            
                            @if($fpData['count'] > 0)
                                <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                                    @foreach($fpData['slots'] as $slot)
                                        <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .6rem;background:rgba(16,185,129,.1);color:#059669;border-radius:var(--radius-md);font-size:.75rem;font-weight:600;">
                                            <i class="fa-solid fa-fingerprint"></i> Slot {{ $slot['id'] }}
                                            @if($slot['leader']) <i class="fa-solid fa-star" style="color:#fbbf24;margin-left:.2rem;" title="Leader/Duress Finger"></i> @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div style="padding:1rem;text-align:center;color:var(--zchpc-text-muted);font-size:0.875rem;border:1px dashed var(--zchpc-border);border-radius:var(--radius-md);">
                            Not synced to any terminals yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection

