@extends('layouts.app')

@section('title', 'Super Fingerprints - ZCHPC Access Control')
@section('header', 'Terminal Super Fingerprints')

@section('content')
@if(session('success'))
    <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.35);border-radius:var(--radius-md);padding:.875rem 1.25rem;margin-bottom:1.25rem;color:#065f46;"><i class="fa-solid fa-circle-check"></i> {{ session('success') }}</div>
@endif
@if(session('error'))
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:var(--radius-md);padding:.875rem 1.25rem;margin-bottom:1.25rem;color:#7f1d1d;"><i class="fa-solid fa-triangle-exclamation"></i> {{ session('error') }}</div>
@endif

<div class="table-card" style="margin-bottom:1.25rem;border-left:4px solid #f59e0b;">
    <div class="table-header"><h2><i class="fa-solid fa-shield-halved" style="color:#d97706;"></i> Enroll or Update a Terminal Administrator Fingerprint</h2></div>
    <div style="padding:1.25rem;">
        <p style="margin:0 0 1rem;color:var(--zchpc-text-muted);font-size:.86rem;">A super fingerprint is an administrator credential for the terminal itself. Select a fingerprint already enrolled on that terminal: the software reads that terminal-held template and writes it back as <code>superFP</code>. No biometric template is stored in this system.</p>
        @if($terminals->isNotEmpty() && $personnel->isNotEmpty())
            <form method="POST" action="{{ route('super-fingerprints.enroll') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.85rem;align-items:end;">
                @csrf
                <div><label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">TERMINAL</label><select id="super-terminal" name="terminal_id" required style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">@foreach($terminals as $terminal)<option value="{{ $terminal->id }}">{{ $terminal->name }} ({{ $terminal->ip }})</option>@endforeach</select></div>
                <div><label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">PERSON</label><select id="super-personnel" name="personnel_id" required style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">@foreach($personnel as $person)<option value="{{ $person->id }}">{{ $person->name }} ({{ $person->employee_no }})</option>@endforeach</select></div>
                <div><label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">RECORDED FINGERPRINT</label><select id="super-fingerprint" name="finger_id" required disabled style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);"><option>Loading fingerprints...</option></select><small id="fingerprint-help" style="display:block;margin-top:.3rem;color:var(--zchpc-text-muted);font-size:.72rem;"></small></div>
                <button id="super-submit" type="submit" class="btn btn-primary" disabled style="justify-content:center;background:#b45309;border-color:#b45309;opacity:.55;cursor:not-allowed;"><i class="fa-solid fa-fingerprint"></i> Promote to Super Fingerprint</button>
            </form>
        @else
            <p style="margin:0;color:var(--zchpc-text-muted);">Add at least one terminal and one person before enrolling a super fingerprint.</p>
        @endif
    </div>
</div>

<div class="table-card">
    <div class="table-header"><h2>Enrollment Requests</h2></div>
    <div class="table-wrapper"><table><thead><tr><th>Terminal</th><th>Person</th><th>Slot</th><th>Status</th><th>Last Updated</th></tr></thead><tbody>
        @forelse($assignments as $assignment)
            <tr>
                <td>{{ $assignment->terminal?->name ?? 'Removed terminal' }}</td>
                <td><strong>{{ $assignment->personnel?->name ?? 'Removed person' }}</strong><br><small style="color:var(--zchpc-text-muted);">{{ $assignment->personnel?->employee_no }}</small></td>
                <td>Fingerprint ID #{{ $assignment->finger_id }}</td>
                <td><span class="badge {{ $assignment->status === 'active' ? 'badge-success' : ($assignment->status === 'failed' ? 'badge-danger' : 'badge-warning') }}">{{ ucfirst($assignment->status) }}</span></td>
                <td>{{ $assignment->updated_at->format('d M Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;padding:3rem;color:var(--zchpc-text-muted);">No super fingerprint enrollments have been requested yet.</td></tr>
        @endforelse
    </tbody></table></div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const terminal = document.getElementById('super-terminal');
    const personnel = document.getElementById('super-personnel');
    const fingerprint = document.getElementById('super-fingerprint');
    const submit = document.getElementById('super-submit');
    const help = document.getElementById('fingerprint-help');
    const endpoint = @json(route('super-fingerprints.records'));

    async function loadFingerprints() {
        fingerprint.disabled = true;
        submit.disabled = true;
        submit.style.opacity = '.55';
        submit.style.cursor = 'not-allowed';
        fingerprint.innerHTML = '<option>Loading recorded fingerprints...</option>';
        help.textContent = '';

        try {
            const response = await fetch(`${endpoint}?terminal_id=${encodeURIComponent(terminal.value)}&personnel_id=${encodeURIComponent(personnel.value)}`, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Could not read fingerprints from the terminal.');
            if (!data.fingerprints.length) {
                fingerprint.innerHTML = '<option>No fingerprint is enrolled for this person</option>';
                help.textContent = 'Enroll a normal fingerprint on this terminal first.';
                return;
            }
            fingerprint.innerHTML = data.fingerprints.map(item => `<option value="${item.id}">${item.label}</option>`).join('');
            fingerprint.disabled = false;
            submit.disabled = false;
            submit.style.opacity = '1';
            submit.style.cursor = 'pointer';
            help.textContent = `${data.fingerprints.length} recorded fingerprint(s) found on this terminal.`;
        } catch (error) {
            fingerprint.innerHTML = '<option>Fingerprints unavailable</option>';
            help.textContent = error.message;
        }
    }

    terminal.addEventListener('change', loadFingerprints);
    personnel.addEventListener('change', loadFingerprints);
    loadFingerprints();
})();
</script>
@endpush
