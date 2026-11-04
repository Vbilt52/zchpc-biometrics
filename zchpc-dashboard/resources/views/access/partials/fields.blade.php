@php
    $tz = config('attendance.timezone');
    $employeeIdLocked = $employeeIdLocked ?? false;
    $beginValue = old('begin_time', $personnel?->begin_time?->copy()->timezone($tz)->format('Y-m-d\TH:i'));
    $endValue = old('end_time', $personnel?->end_time?->copy()->timezone($tz)->format('Y-m-d\TH:i'));
    $selectedDepartment = old('department', $personnel?->access_group_id);
@endphp

@if($personnel?->photo_path)
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;">
        <img src="{{ $personnel->photoUrl() }}?v={{ $personnel->updated_at?->timestamp }}"
             alt="Photo of {{ $personnel->name }}"
             style="width:64px;height:64px;object-fit:cover;border-radius:50%;border:1px solid var(--zchpc-border);">
        <span class="hint">Current photo. Uploading a new one replaces it.</span>
    </div>
@endif

<div class="form-grid">
    <div class="form-field">
        <label class="form-label" for="name">Full name</label>
        <input class="form-input" id="name" name="name" value="{{ old('name', $personnel?->name) }}" maxlength="64" required autocomplete="off">
        @error('name')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="employee_no">Employee / user ID</label>
        <input class="form-input" id="employee_no" name="employee_no" value="{{ old('employee_no', $personnel?->employee_no) }}"
               maxlength="32" required pattern="[A-Za-z0-9._-]+" autocomplete="off"
               @if($employeeIdLocked) readonly style="background:var(--zchpc-bg);" @endif>
        @if($employeeIdLocked)
            <p class="hint">Locked: this ID is already tied to a device credential and access history.</p>
        @else
            <p class="hint">Letters, numbers, dots, hyphens and underscores. Must be unique.</p>
        @endif
        @error('employee_no')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="department">Department / access group</label>
        <select class="form-select" id="department" name="department" required>
            <option value="">Choose a department</option>
            @foreach($departments as $department)
                <option value="{{ $department->id }}" @selected((string) $selectedDepartment === (string) $department->id)>{{ $department->name }}</option>
            @endforeach
        </select>
        @error('department')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="user_type">Person type</label>
        <select class="form-select" id="user_type" name="user_type" required>
            <option value="normal" @selected(old('user_type', $personnel?->user_type ?? 'normal') === 'normal')>Staff / normal user</option>
            <option value="visitor" @selected(old('user_type', $personnel?->user_type) === 'visitor')>Visitor</option>
        </select>
        @error('user_type')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="begin_time">Access starts</label>
        <input class="form-input" id="begin_time" type="datetime-local" name="begin_time" value="{{ $beginValue }}" required>
        @error('begin_time')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="end_time">Access expires</label>
        <input class="form-input" id="end_time" type="datetime-local" name="end_time" value="{{ $endValue }}" required>
        <p class="hint">The controller enforces this window. Leave long for permanent staff.</p>
        @error('end_time')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="phone_number">Phone (optional)</label>
        <input class="form-input" id="phone_number" name="phone_number" value="{{ old('phone_number', $personnel?->phone_number) }}" maxlength="32" autocomplete="off">
        @error('phone_number')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="email">Email (optional)</label>
        <input class="form-input" id="email" type="email" name="email" value="{{ old('email', $personnel?->email) }}" maxlength="120" autocomplete="off">
        @error('email')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label" for="photo">Photo (optional)</label>
        <input class="form-input" id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
        <p class="hint">Stored privately. Maximum {{ config('access.photos.max_kilobytes', 2048) / 1024 }} MB.</p>
        @error('photo')<p class="form-error">{{ $message }}</p>@enderror
    </div>
</div>

<div class="form-field">
    <label class="form-label" for="notes">Notes (optional)</label>
    <textarea class="form-textarea" id="notes" name="notes" maxlength="1000" rows="3">{{ old('notes', $personnel?->notes) }}</textarea>
    @error('notes')<p class="form-error">{{ $message }}</p>@enderror
</div>

@if(isset($duplicate_matches) && $duplicate_matches->isNotEmpty())
    <label style="display:flex;gap:.6rem;align-items:flex-start;font-size:.85rem;cursor:pointer;padding:.6rem .75rem;border:1px dashed var(--warning);border-radius:var(--radius-md);background:rgba(245,158,11,.05);">
        <input type="checkbox" name="confirm_duplicate" value="1" @checked(old('confirm_duplicate')) style="margin-top:.2rem;">
        <span>I confirm this is a separate person from the records listed above.</span>
    </label>
    @error('confirm_duplicate')<p class="form-error">{{ $message }}</p>@enderror
@endif
