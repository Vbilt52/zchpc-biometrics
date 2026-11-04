<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\AttendanceCheckout;
use App\Models\Terminal;
use App\Services\EloquentDeviceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

class TerminalController extends Controller
{
    public function index(DeviceManager $deviceManager)
    {
        $terminals = Terminal::all();
        $statuses = [];

        foreach ($terminals as $terminal) {
            // Read the persisted, timestamped status; the cache flag is only a
            // display convenience and may have expired between checks.
            $statuses[$terminal->id] = $terminal->isOnline();
        }

        return view('terminals.index', compact('terminals', 'statuses'));
    }

    public function create()
    {
        return view('terminals.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:terminals',
            'phase' => 'required|string|max:100',
            'ip' => 'required|string|ip',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required_if:integration,'.Terminal::INTEGRATION_HIKVISION_ISAPI.'|nullable|string|max:255',
            'password' => 'required_if:integration,'.Terminal::INTEGRATION_HIKVISION_ISAPI.'|nullable|string|max:255',
            'integration' => ['required', Rule::in([Terminal::INTEGRATION_HIKVISION_ISAPI, Terminal::INTEGRATION_STANDALONE_WIEGAND])],
            'model' => 'nullable|string|max:100',
            'protocol' => ['required', Rule::in(['http', 'https'])],
        ]);

        $validated['verify_ssl'] = $request->boolean('verify_ssl');
        $validated['timeout'] = 5;
        $validated['location'] = null;

        $terminal = Terminal::create($validated);

        $this->resetCache();
        Artisan::call('terminals:ping', ['--force' => true, '--device' => $terminal->name]);

        return redirect()->route('terminals.index')->with('success', 'Terminal added successfully.');
    }

    public function edit(Terminal $terminal)
    {
        return view('terminals.edit', compact('terminal'));
    }

    public function update(Request $request, Terminal $terminal)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('terminals')->ignore($terminal->id)],
            'phase' => 'required|string|max:100',
            'ip' => 'required|string|ip',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required_if:integration,'.Terminal::INTEGRATION_HIKVISION_ISAPI.'|nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'integration' => ['required', Rule::in([Terminal::INTEGRATION_HIKVISION_ISAPI, Terminal::INTEGRATION_STANDALONE_WIEGAND])],
            'model' => 'nullable|string|max:100',
            'protocol' => ['required', Rule::in(['http', 'https'])],
        ]);

        $validated['verify_ssl'] = $request->boolean('verify_ssl');
        $validated['timeout'] = 5;
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $terminal->fill($validated);
        if ($terminal->isDirty(['ip', 'port', 'username', 'password', 'protocol', 'integration', 'verify_ssl'])) {
            $terminal->forceFill(['status' => Terminal::STATUS_UNKNOWN, 'last_checked_at' => null, 'last_seen_at' => null, 'last_error' => null, 'consecutive_failures' => 0]);
            Cache::forget("terminal.{$terminal->id}.online");
        }
        $terminal->save();

        $this->resetCache();
        Artisan::call('terminals:ping', ['--force' => true, '--device' => $terminal->name]);

        return redirect()->route('terminals.index')->with('success', 'Terminal updated successfully.');
    }

    public function destroy(Terminal $terminal)
    {
        if (AccessEvent::where('terminal_id', $terminal->id)->exists()
            || AttendanceCheckout::where('terminal_id', $terminal->id)->exists()) {
            return redirect()->route('terminals.index')->with('error', 'This terminal has historical access or attendance records and cannot be deleted.');
        }

        $terminal->delete();
        $this->resetCache();

        return redirect()->route('terminals.index')->with('success', 'Terminal removed successfully.');
    }

    // -------------------------------------------------------------------------

    /**
     * Flush both the Eloquent provider in-memory cache and the DeviceManager
     * config cache so the next page load reads fresh data from the DB.
     */
    private function resetCache(): void
    {
        app(EloquentDeviceProvider::class)->clearCache();
        app(DeviceManager::class)->reload();
    }
}
