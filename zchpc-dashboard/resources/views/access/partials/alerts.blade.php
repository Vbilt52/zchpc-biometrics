@if(session('success'))
    <div class="notice notice-success" role="status">
        <i class="fa-solid fa-circle-check" style="margin-top:.1rem;"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

@if(session('error'))
    <div class="notice notice-error" role="alert">
        <i class="fa-solid fa-triangle-exclamation" style="margin-top:.1rem;"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

@if(session('warning'))
    <div class="notice notice-warning" role="status">
        <i class="fa-solid fa-circle-exclamation" style="margin-top:.1rem;"></i>
        <div>{{ session('warning') }}</div>
    </div>
@endif

@if(isset($duplicate_matches) && $duplicate_matches->isNotEmpty())
    <div class="notice notice-warning">
        <i class="fa-solid fa-users-viewfinder" style="margin-top:.1rem;"></i>
        <div>
            <strong>Possible duplicate people</strong>
            <p style="margin:.3rem 0 .5rem;">The employee ID must be unique. Check whether one of these records is the same person:</p>
            <ul style="margin:0 0 .5rem 1rem;padding:0;">
                @foreach($duplicate_matches as $match)
                    <li>
                        <strong>{{ $match->name }}</strong> · {{ $match->employee_no }}
                        <span style="color:var(--zchpc-text-muted);">· {{ $match->department }}</span>
                    </li>
                @endforeach
            </ul>
            <p style="margin:0;color:var(--zchpc-text-muted);font-size:.8rem;">
                If this is a different person, tick “I confirm this is a separate person” and submit again.
            </p>
        </div>
    </div>
@endif
