<?php

namespace App\Http\Controllers;

use App\Services\DeviceActivityLogger;
use App\Services\PersonnelDeviceAccessService;
use App\Support\PersonnelListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BulkPersonnelDeletionController extends Controller
{
    public function __invoke(Request $request, PersonnelDeviceAccessService $access): RedirectResponse
    {
        $legacy = $request->routeIs('personnel.bulk-destroy');
        Gate::authorize($legacy ? 'personnel.deactivate' : 'access.manage');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', Rule::exists('personnel', 'id')->whereNull('deleted_at')],
            'confirm' => ['required', 'accepted'],
        ]);

        try {
            $result = $access->removeMany(array_map('intval', $data['ids']), $request->user(), DeviceActivityLogger::requestIp());
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);

            return back()->with('error', 'No selected users were deleted. '.$error->getMessage());
        }

        return redirect($legacy ? PersonnelListState::url($request) : route('access.index'))->with('success',
            "{$result['removed']} selected user(s) removed locally. {$result['pending']} device removal(s) queued. Offline devices may retain access until they reconnect and confirm removal.");
    }
}
