<section class="table-card" style="margin-bottom:1.25rem;">
    <div class="table-header"><div>
        <h2>Device access assignments</h2>
        <p style="font-size:.85rem;color:var(--zchpc-text-muted);margin-top:.4rem;">Assigned to {{ $personnel->terminals->where('pivot.access_allowed', true)->count() }} of {{ $terminals->count() }} devices. Access also depends on status, validity dates and an enrolled credential.</p>
    </div></div>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>Device</th><th>Assignment</th><th>Device confirmation</th></tr></thead>
        <tbody>@foreach($terminals as $terminal)
            @php
                $assignment = $personnel->terminals->firstWhere('id', $terminal->id);
                $pending = $personnel->pendingSyncs->firstWhere('terminal_id', $terminal->id);
            @endphp
            <tr><td><strong>{{ $terminal->name }}</strong><br><small>{{ $terminal->location }}</small></td>
                <td>{{ $assignment?->pivot->access_allowed ? 'Assigned' : 'Not assigned' }}</td>
                <td>
                    @if($pending)
                        <strong style="color:#b45309;">{{ $assignment?->pivot->access_allowed ? 'Update pending' : 'Revocation pending — access may remain' }}</strong>
                        <br><small>{{ $pending->last_error }}</small>
                    @elseif($assignment?->pivot->sync_status === 'confirmed')
                        <strong style="color:#047857;">{{ $assignment->pivot->access_allowed && $personnel->valid_enabled && $personnel->user_type !== 'blocklist' ? 'Profile and dates confirmed' : 'Access denied / profile absent confirmed' }}</strong>
                    @elseif($assignment?->pivot->sync_status === 'imported')
                        Imported from device — changes not yet verified
                    @else
                        Not verified on device
                    @endif
                    @if($assignment?->pivot->synced_at)<br><small>Last confirmation/import: {{ \Carbon\Carbon::parse($assignment->pivot->synced_at)->timezone(config('attendance.timezone'))->format('d M Y H:i') }}</small>@endif
                </td>
            </tr>
        @endforeach</tbody>
    </table></div>
    <div style="padding:1rem;display:flex;gap:.75rem;flex-wrap:wrap;">
        <a class="btn btn-primary" href="{{ route('personnel.edit', $personnel) }}">Edit assignments and dates</a>
        <form action="{{ route('personnel.sync', $personnel) }}" method="POST">@csrf<button class="btn btn-outline">Retry existing assignments</button></form>
    </div>
</section>
