@php
    $cancelUrl = $cancelUrl ?? null;
    $retryUrl = $retryUrl ?? null;
@endphp

@if($enrollment)
    <div id="enrollment-status" class="notice notice-{{ $enrollment->status === 'confirmed' ? 'success' : ($enrollment->status === 'failed' || $enrollment->status === 'timed_out' ? 'error' : 'info') }}"
         data-status-url="{{ route('access.enrollments.show', $enrollment) }}"
         data-active="{{ $enrollment->isActive() ? '1' : '0' }}">
        <i class="fa-solid {{ $enrollment->isActive() ? 'fa-spinner fa-spin' : ($enrollment->status === 'confirmed' ? 'fa-circle-check' : 'fa-circle-info') }}" style="margin-top:.15rem;"></i>
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                <strong id="enrollment-label">{{ $enrollment->label() }}</strong>
                <span id="enrollment-badge" class="badge badge-{{ $enrollment->tone() === 'muted' ? 'muted' : $enrollment->tone() }}">
                    {{ str_replace('_', ' ', $enrollment->status) }}
                </span>
            </div>
            <p id="enrollment-message" style="margin:.3rem 0 0;font-size:.85rem;">{{ $enrollment->message }}</p>
            <p class="hint" style="margin:.3rem 0 0;">
                {{ $enrollment->fingerLabelText() }} · {{ $enrollment->terminal_name }}
            </p>

            @if($enrollment->isActive() && $cancelUrl)
                <form method="POST" action="{{ $cancelUrl }}" style="margin-top:.6rem;">
                    @csrf
                    <button class="btn btn-outline" style="padding:.3rem .65rem;" type="submit">Cancel enrollment</button>
                </form>
            @elseif(in_array($enrollment->status, ['failed', 'timed_out', 'cancelled'], true) && $retryUrl)
                <form method="POST" action="{{ $retryUrl }}" style="margin-top:.6rem;">
                    @csrf
                    <button class="btn btn-outline" style="padding:.3rem .65rem;"
                            @disabled($enrollment->terminal && ! $enrollment->terminal->isOnline())>
                        <i class="fa-solid fa-rotate-right"></i> Retry this finger
                    </button>
                </form>
            @endif
        </div>
    </div>
@endif

@push('scripts')
<script>
(() => {
    const panel = document.getElementById('enrollment-status');
    if (!panel || panel.dataset.active !== '1') return;

    const url = panel.dataset.statusUrl;
    let attempts = 0;

    const timer = setInterval(async () => {
        attempts += 1;
        if (attempts > 90) { clearInterval(timer); return; }

        try {
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await response.json();

            document.getElementById('enrollment-label').textContent = data.label;
            document.getElementById('enrollment-message').textContent = data.message || '';

            const badge = document.getElementById('enrollment-badge');
            if (badge) {
                const tone = data.tone === 'muted' ? 'muted' : data.tone;
                badge.className = 'badge badge-' + tone;
                badge.textContent = data.status.replace(/_/g, ' ');
            }

            if (!data.is_active) {
                clearInterval(timer);
                window.location.reload();
            }
        } catch (error) {
            // A transient polling failure is not an enrollment failure; keep trying.
        }
    }, {{ (int) config('access.enrollment.poll_seconds', 2) * 1000 }});
})();
</script>
@endpush
