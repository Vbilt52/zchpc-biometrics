@php
    $editing = isset($personnel);
    $zone = config('attendance.timezone');
    $selected = session()->hasOldInput() ? old('terminal_ids', []) : ($syncedIds ?? []);
@endphp
<div class="table-card" style="padding:1.5rem;margin-bottom:1.25rem;">
    <h2>Personal details and access dates</h2>
    <p class="form-help">Dates use {{ $zone }}. Access starts and expires at the times below. Department and date added are local records; name, phone, access status, validity and device permissions are sent to the devices.</p>
    <div class="person-fields">
        <div><label for="employee_no">Employee ID</label><input id="employee_no" name="employee_no" value="{{ old('employee_no', $personnel->employee_no ?? '') }}" maxlength="32" required @readonly($editing && $personnel->terminals->isNotEmpty())>
            <small>Device-linked IDs stay fixed to protect fingerprint ownership and history.</small></div>
        <div><label for="person-name">Full name</label><input id="person-name" name="name" value="{{ old('name', $personnel->name ?? '') }}" placeholder="e.g. Prince" maxlength="64" required></div>
        <div><label for="person-type">User type</label><select id="person-type" name="user_type">
            @foreach(['normal' => 'Normal user', 'visitor' => 'Visitor', 'blocklist' => 'Blocklist — deny access'] as $value => $label)
                <option value="{{ $value }}" @selected(old('user_type', $personnel->user_type ?? 'normal') === $value)>{{ $label }}</option>
            @endforeach
        </select></div>
        <div><label for="valid-enabled">Access status</label><select id="valid-enabled" name="valid_enabled">
            <option value="1" @selected((string) old('valid_enabled', ($personnel->valid_enabled ?? true) ? '1' : '0') === '1')>Enabled within access dates</option>
            <option value="0" @selected((string) old('valid_enabled', ($personnel->valid_enabled ?? true) ? '1' : '0') === '0')>Disabled — deny access on assigned devices</option>
        </select></div>
        <div><label for="phone-number">Phone number</label><input id="phone-number" name="phone_number" value="{{ old('phone_number', $personnel->phone_number ?? '') }}" maxlength="32"></div>
        <div><label for="access-group">Department / access group</label><select id="access-group" name="access_group_id" required><option value="">Select department</option>
            @foreach($accessGroups as $group)<option value="{{ $group->id }}" @selected((string) old('access_group_id', $personnel->access_group_id ?? '') === (string) $group->id)>{{ $group->name }}</option>@endforeach
        </select></div>
        <div><label for="added-at">Date added (record date)</label><input type="datetime-local" id="added-at" name="added_at" value="{{ old('added_at', ($personnel->added_at ?? now())->copy()->timezone($zone)->format('Y-m-d\TH:i')) }}"><small>Does not change the original audit creation timestamp.</small></div>
        <div><label for="begin-time">Access starts</label><input type="datetime-local" id="begin-time" name="begin_time" required value="{{ old('begin_time', ($personnel->begin_time ?? now())->copy()->timezone($zone)->format('Y-m-d\TH:i')) }}"></div>
        <div><label for="end-time">Access expires</label><input type="datetime-local" id="end-time" name="end_time" required value="{{ old('end_time', ($personnel->end_time ?? now()->addYear())->copy()->timezone($zone)->format('Y-m-d\TH:i')) }}"></div>
    </div>
</div>
<div class="table-card" style="padding:1.5rem;margin-bottom:1.25rem;">
    <h2>Devices this person may access</h2>
    <p class="form-help">Select only the permitted devices. For example, select Main Entrance for Prince and leave Server Room unchecked. Unchecking a device requests revocation there. Offline changes remain pending until confirmed.</p>
    <input type="hidden" name="assignments_submitted" value="1">
    <div class="person-fields">
        @forelse($terminals as $terminal)
            <label class="device-choice"><input type="checkbox" name="terminal_ids[]" value="{{ $terminal->id }}" @checked(in_array($terminal->id, $selected))>
                <span style="flex:1;">
                    <span style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;">
                        <strong>{{ $terminal->name }}</strong>
                        @if($terminal->isOnline())
                            <span class="badge badge-success" style="font-size:0.7rem;padding:2px 6px;"><i class="fa-solid fa-circle" style="font-size:0.5rem;vertical-align:middle;margin-right:2px;"></i> Online</span>
                        @else
                            <span class="badge badge-danger" style="font-size:0.7rem;padding:2px 6px;"><i class="fa-solid fa-circle" style="font-size:0.5rem;vertical-align:middle;margin-right:2px;"></i> {{ $terminal->statusLabel() }}</span>
                        @endif
                    </span>
                    <small>{{ $terminal->phase }} · {{ $terminal->location ?: $terminal->ip }}</small>
                </span>
            </label>
        @empty<p>Add an API-managed terminal to assign access.</p>@endforelse
    </div>
    <details style="margin-top:1.25rem;"><summary>Advanced: door channels inside each device</summary>
        <p class="form-help">A single-door fingerprint terminal normally uses channel 1. These numbers are internal device channels; they are not the four devices listed above.</p>
        <label for="door-right">Door channels (comma separated)</label><input id="door-right" name="door_right" value="{{ old('door_right', $personnel->door_right ?? '1') }}" pattern="[0-9]+(,[0-9]+)*" required>
    </details>
</div>
<style>
    .person-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; margin-top:1rem; }
    .person-fields label, details > label { display:block; font-size:.85rem; font-weight:600; margin-bottom:.35rem; }
    .person-fields input:not([type=checkbox]), .person-fields select, #door-right { width:100%; padding:.7rem; border:1px solid var(--zchpc-border); border-radius:.5rem; font:inherit; background:white; }
    .person-fields small { display:block; color:var(--zchpc-text-muted); font-size:.75rem; margin-top:.3rem; }
    .form-help { color:var(--zchpc-text-muted); font-size:.85rem; margin:.6rem 0; }
    .person-fields .device-choice { display:flex; align-items:center; gap:.75rem; padding:1rem; border:1px solid var(--zchpc-border); border-radius:.5rem; cursor:pointer; }
    .device-choice:has(input:checked) { border-color:var(--zchpc-primary); background:#eff6ff; }
</style>
