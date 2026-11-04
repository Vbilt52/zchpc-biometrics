@php
    $checkedDoors = array_map('intval', old('door_ids', $selectedDoorIds ?? []));
@endphp

<div class="door-picker">
    @forelse($doors as $building => $floors)
        <div class="door-group-title" style="margin-top:.75rem;">{{ $building }}</div>
        @foreach($floors as $floor => $zones)
            <div style="padding-left:.25rem;">
                <div style="font-size:.74rem;color:var(--zchpc-text);font-weight:600;margin:.35rem 0 .15rem;">{{ $floor }}</div>
                @foreach($zones as $zone => $zoneDoors)
                    <div style="padding-left:.5rem;">
                        <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--zchpc-text-muted);margin:.25rem 0 .1rem;">{{ $zone }}</div>
                        @foreach($zoneDoors as $door)
                            <label class="door-option">
                                <input type="checkbox" name="door_ids[]" value="{{ $door->id }}"
                                       @checked(in_array($door->id, $checkedDoors, true))>
                                <span>
                                    {{ $door->name }}
                                    <small>
                                        {{ $door->controllerLabel() }} · {{ $door->statusLabel() }}
                                    </small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
    @empty
        <div style="padding:.75rem;color:var(--zchpc-text-muted);font-size:.85rem;">
            No doors are registered yet.
            <a href="{{ route('access.doors.index') }}" style="color:var(--zchpc-primary);font-weight:600;">Register doors and controllers</a>
            before assigning access.
        </div>
    @endforelse
</div>
