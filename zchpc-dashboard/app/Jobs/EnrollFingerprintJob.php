<?php

namespace App\Jobs;

use App\Models\FingerprintEnrollment;
use App\Services\AccessAuditLogger;
use App\Services\FingerprintEnrollmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the blocking capture request away from the browser. The enrollment row
 * is the progress channel the page polls.
 */
class EnrollFingerprintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $enrollmentId)
    {
        $this->timeout = max(30, (int) config('access.enrollment.timeout_seconds', 60) + 30);
    }

    public function handle(FingerprintEnrollmentService $service): void
    {
        $enrollment = FingerprintEnrollment::query()->find($this->enrollmentId);

        if ($enrollment === null) {
            return;
        }

        $service->process($enrollment);
    }

    public function failed(?\Throwable $error): void
    {
        $enrollment = FingerprintEnrollment::query()->find($this->enrollmentId);

        if ($enrollment === null || ! $enrollment->isActive()) {
            return;
        }

        $enrollment->update([
            'status' => FingerprintEnrollment::STATUS_FAILED,
            'message' => 'The enrollment worker stopped before the terminal answered. '
                .($error?->getMessage() ?? 'No response was recorded.'),
            'finished_at' => now(),
        ]);

        app(AccessAuditLogger::class)->log('enrollment_failed', 'failure',
            'Fingerprint enrollment #'.$this->enrollmentId.' for '.$enrollment->person_name.' did not complete.',
            ['enrollment_id' => $this->enrollmentId], personnel: $enrollment->personnel, terminal: $enrollment->terminal);
    }
}
