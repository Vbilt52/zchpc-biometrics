<?php

namespace App\Http\Controllers;

use App\Models\FingerprintEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Polling endpoint for the capture screen. Metadata only: never a template. */
class AccessEnrollmentController extends Controller
{
    public function show(FingerprintEnrollment $enrollment): JsonResponse
    {
        Gate::authorize('access.view');

        $confirmedForPerson = $enrollment->personnel_id !== null
            ? FingerprintEnrollment::query()
                ->where('personnel_id', $enrollment->personnel_id)
                ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)
                ->distinct()->count('finger_id')
            : 0;

        return response()->json([
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'label' => $enrollment->label(),
            'tone' => $enrollment->tone(),
            'message' => $enrollment->message,
            'terminal_name' => $enrollment->terminal_name,
            'finger_id' => $enrollment->finger_id,
            'finger_label' => $enrollment->fingerLabelText(),
            'is_active' => $enrollment->isActive(),
            'is_confirmed' => $enrollment->status === FingerprintEnrollment::STATUS_CONFIRMED,
            'confirmed_count' => $confirmedForPerson,
            'started_at' => $enrollment->started_at?->toIso8601String(),
            'finished_at' => $enrollment->finished_at?->toIso8601String(),
        ]);
    }
}
