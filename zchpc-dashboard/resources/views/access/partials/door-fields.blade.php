@php
    $door = $door ?? null;
    $activeValue = old('is_active', $door?->is_active ?? true);
@endphp

<div class="form-grid">
    <div class="form-field">
        <label class="form-label">Door name</label>
        <input class="form-input" name="name" value="{{ old('name', $door?->name) }}" maxlength="80" required>
        @error('name')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Door code (optional)</label>
        <input class="form-input" name="code" value="{{ old('code', $door?->code) }}" maxlength="40" placeholder="e.g. HQ-1-SERVER">
        @error('code')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Building</label>
        <input class="form-input" name="building" value="{{ old('building', $door?->building) }}" maxlength="80" placeholder="e.g. Head Office">
        @error('building')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Floor</label>
        <input class="form-input" name="floor" value="{{ old('floor', $door?->floor) }}" maxlength="40" placeholder="e.g. Ground floor">
        @error('floor')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Zone</label>
        <input class="form-input" name="zone" value="{{ old('zone', $door?->zone) }}" maxlength="80" placeholder="e.g. Data centre">
        @error('zone')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Controller</label>
        <select class="form-select" name="terminal_id">
            <option value="">No controller</option>
            @foreach($terminals as $terminal)
                <option value="{{ $terminal->id }}" @selected((string) old('terminal_id', $door?->terminal_id) === (string) $terminal->id)>
                    {{ $terminal->name }} · {{ $terminal->statusLabel() }}
                </option>
            @endforeach
        </select>
        <p class="hint">The door can only be enforced where a managed controller exists.</p>
        @error('terminal_id')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Controller channel</label>
        <input class="form-input" type="number" name="door_no" value="{{ old('door_no', $door?->door_no ?? 1) }}" min="1" max="64" required>
        @error('door_no')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Plan template number</label>
        <input class="form-input" name="plan_template_no" value="{{ old('plan_template_no', $door?->plan_template_no ?? '1') }}" maxlength="16" required>
        <p class="hint">Schedule template stored on the terminal. 1 is the terminal's default plan.</p>
        @error('plan_template_no')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-field">
        <label class="form-label">Display order</label>
        <input class="form-input" type="number" name="sort_order" value="{{ old('sort_order', $door?->sort_order ?? 0) }}" min="0" max="999">
        @error('sort_order')<p class="form-error">{{ $message }}</p>@enderror
    </div>
</div>

<div class="form-field">
    <label class="form-label">Notes (optional)</label>
    <input class="form-input" name="notes" value="{{ old('notes', $door?->notes) }}" maxlength="255">
    @error('notes')<p class="form-error">{{ $message }}</p>@enderror
</div>

<label style="display:flex;gap:.5rem;align-items:center;font-size:.84rem;margin-bottom:.5rem;">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked((bool) $activeValue)>
    In service (available for new permissions)
</label>
