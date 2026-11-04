@php
    $controllerStates = $controllerStates ?? [];
@endphp

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Controller</th>
                <th>Doors</th>
                <th>State</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
        @forelse($controllerStates as $state)
            <tr>
                <td>
                    <div style="font-weight:600;">{{ $state['terminal']->name }}</div>
                    <div class="hint">{{ $state['terminal']->ip }}:{{ $state['terminal']->port }} · {{ $state['terminal']->statusLabel() }}</div>
                </td>
                <td>
                    @if($state['doors']->isEmpty())
                        <span class="hint">None granted</span>
                    @else
                        {{ $state['doors']->pluck('name')->implode(', ') }}
                    @endif
                </td>
                <td>
                    <span class="badge badge-{{ $state['tone'] === 'muted' ? 'muted' : $state['tone'] }}">{{ $state['label'] }}</span>
                </td>
                <td>
                    @if(! $state['terminal']->isOnline() && in_array($state['state'], ['pending', 'revoking'], true))
                        <span style="color:#b45309;font-size:.78rem;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Controller offline — it may still enforce previously stored permissions until it confirms.
                        </span>
                    @elseif($state['message'])
                        <span class="hint">{{ $state['message'] }}</span>
                    @else
                        <span class="hint">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="hint" style="text-align:center;padding:1.5rem;">No controllers are configured.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
