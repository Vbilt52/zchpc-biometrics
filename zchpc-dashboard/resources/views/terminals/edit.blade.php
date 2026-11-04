@extends('layouts.app')

@section('content')
<div class="content-wrapper">
    <div class="table-header" style="border-radius: var(--radius-xl) var(--radius-xl) 0 0; background: white; margin-bottom: 0;">
        <h2 class="header-title">Edit Terminal: {{ $terminal->name }}</h2>
        <a href="{{ route('terminals.index') }}" class="btn btn-outline">
            <i class="fa-solid fa-arrow-left"></i> Back to Terminals
        </a>
    </div>

    <div class="table-card" style="border-top-left-radius: 0; border-top-right-radius: 0; padding: 2rem;">
        
        @if ($errors->any())
            <div style="background: rgba(239,68,68,0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 2rem;">
                <ul style="margin-left: 1.5rem;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('terminals.update', $terminal) }}" method="POST">
            @csrf
            @method('PUT')
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Terminal Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $terminal->name) }}" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Phase <span style="color:var(--danger);">*</span></label>
                    <select name="phase" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                        <option value="Phase 1" {{ old('phase', $terminal->phase) == 'Phase 1' ? 'selected' : '' }}>Phase 1</option>
                        <option value="Phase 2" {{ old('phase', $terminal->phase) == 'Phase 2' ? 'selected' : '' }}>Phase 2</option>
                        <option value="Phase 3" {{ old('phase', $terminal->phase) == 'Phase 3' ? 'selected' : '' }}>Phase 3</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Integration <span style="color:var(--danger);">*</span></label>
                    <select name="integration" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                        <option value="{{ \App\Models\Terminal::INTEGRATION_HIKVISION_ISAPI }}" @selected(old('integration', $terminal->integration) === \App\Models\Terminal::INTEGRATION_HIKVISION_ISAPI)>Hikvision ISAPI — full API management</option>
                        <option value="{{ \App\Models\Terminal::INTEGRATION_STANDALONE_WIEGAND }}" @selected(old('integration', $terminal->integration) === \App\Models\Terminal::INTEGRATION_STANDALONE_WIEGAND)>Standalone / Wiegand — inventory only</option>
                    </select>
                    <p style="margin:.35rem 0 0;font-size:.75rem;color:var(--zchpc-text-muted);">Changing to standalone disables API actions to prevent unsupported device commands.</p>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Model</label>
                    <input type="text" name="model" value="{{ old('model', $terminal->model) }}" maxlength="100" style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);" placeholder="e.g. DS-K1T341AMF">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">IP Address <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="ip" value="{{ old('ip', $terminal->ip) }}" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Port</label>
                    <input type="number" name="port" value="{{ old('port', $terminal->port) }}" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Protocol</label>
                    <select name="protocol" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                        <option value="http" @selected(old('protocol', $terminal->protocol) === 'http')>HTTP</option>
                        <option value="https" @selected(old('protocol', $terminal->protocol) === 'https')>HTTPS</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">User Name</label>
                    <input type="text" name="username" value="{{ old('username', $terminal->username) }}" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Password <small style="color: var(--zchpc-text-muted); font-weight: 400;">(Leave blank to keep unchanged)</small></label>
                    <input type="password" name="password" style="width: 100%; padding: 0.75rem; border: 1px solid var(--zchpc-border); border-radius: var(--radius-md);">
                </div>
                <div style="grid-column: 1 / -1; display: flex; align-items: center;">
                    <input type="checkbox" name="verify_ssl" id="verify_ssl" value="1" {{ old('verify_ssl', $terminal->verify_ssl) ? 'checked' : '' }} style="margin-right: 0.5rem; transform: scale(1.2);">
                    <label for="verify_ssl" style="font-weight: 500; cursor: pointer;">Verify HTTPS certificate</label>
                </div>
            </div>

            <hr style="border: none; border-top: 1px solid var(--zchpc-border); margin: 2rem 0;">

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Update Terminal</button>
            </div>
        </form>
    </div>
</div>
@endsection
