{{-- Access-group door templates. A group grants exactly the doors selected here. --}}
<div style="margin-top:1.25rem;">
    <label class="form-label">Doors granted by this group <span style="font-weight:400;color:var(--zchpc-text-muted);">(optional)</span></label>
    <p style="margin:.25rem 0 .6rem;color:var(--zchpc-text-muted);font-size:.8rem;">
        Applying this group to a person selects these doors. It does not grant them on its own.
    </p>

    <div style="max-height:320px;overflow:auto;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.5rem .75rem;">
        @forelse($doors as $building => $buildingDoors)
            <div style="margin-bottom:.75rem;">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--zchpc-text-muted);font-weight:600;margin-bottom:.35rem;">{{ $building }}</div>
                @foreach($buildingDoors as $door)
                    <label style="display:flex;gap:.6rem;align-items:flex-start;padding:.3rem 0;font-size:.875rem;cursor:pointer;">
                        <input type="checkbox" name="door_ids[]" value="{{ $door->id }}"
                            @checked(in_array($door->id, $selectedDoorIds ?? [], true)) style="margin-top:.2rem;">
                        <span>
                            {{ $door->name }}
                            <small style="display:block;color:var(--zchpc-text-muted);">
                                {{ $door->zone ?: 'No zone' }} · {{ $door->terminal?->name ?? 'No controller' }}
                            </small>
                        </span>
                    </label>
                @endforeach
            </div>
        @empty
            <p style="color:var(--zchpc-text-muted);font-size:.85rem;margin:.5rem 0;">
                No doors are registered yet. <a href="{{ route('access.doors.index') }}" style="color:var(--zchpc-primary);">Register doors and controllers</a> first.
            </p>
        @endforelse
    </div>
</div>
