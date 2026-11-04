<section class="table-card" style="margin-bottom:1.25rem;">
    <div class="table-header"><div>
        <h2>Automatic log collection</h2>
        <p style="margin:.35rem 0 0;color:var(--zchpc-text-muted);font-size:.85rem;">Scheduled every minute while the server scheduler is running. Older history can be imported using the date range below. Times shown in {{ config('attendance.timezone') }}.</p>
    </div></div>
    <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>Terminal</th><th>Collection status</th><th>Automatic coverage</th><th>Last successful collection</th></tr></thead>
            <tbody>
            @forelse($terminals->filter(fn ($terminal) => $terminal->supports('event_import')) as $terminal)
                @php($sync = $syncStates->get($terminal->id))
                <tr>
                    <td>{{ $terminal->name }}</td>
                    <td>
                        @if($sync?->last_error)
                            <strong style="color:#b45309;">Needs attention</strong><br><small>{{ $sync->last_error }}</small>
                        @elseif(!$sync?->last_success_at)
                            Waiting for first collection
                        @elseif($sync->last_success_at->lt(now()->subMinutes(5)))
                            <strong style="color:#b45309;">Collection overdue</strong><br><small>Check the server scheduler.</small>
                        @elseif($sync->through_at->lt(now()->subMinutes(5)))
                            Catching up
                        @else
                            <strong style="color:#047857;">Up to date</strong>
                        @endif
                    </td>
                    <td>@if($sync?->through_at)
                        {{ $sync->started_at->copy()->timezone(config('attendance.timezone'))->format('d M H:i') }} – {{ $sync->through_at->copy()->timezone(config('attendance.timezone'))->format('d M H:i:s') }}
                    @else No completed window @endif</td>
                    <td>{{ $sync?->last_success_at?->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i:s') ?? 'Never' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Add a Hikvision ISAPI terminal to enable automatic collection.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
