<?php

namespace App\Services;

use App\Models\FingerprintEnrollment;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Support\Collection;

/**
 * Keeps the in-progress new-user setup in the session.
 *
 * The person row itself is created at step 1 with status `draft`, so an
 * abandoned setup is visible and can be discarded rather than silently leaving
 * a half-registered identity behind. Device assignments are only committed at
 * the final step.
 */
class AccessWizard
{
    private const KEY = 'access.wizard';

    /** @return array<string, mixed> */
    public function state(): array
    {
        return (array) session(self::KEY, []);
    }

    /** @param array<string, mixed> $values */
    public function put(array $values): void
    {
        session([self::KEY => array_merge($this->state(), $values)]);
    }

    public function forget(): void
    {
        session()->forget(self::KEY);
    }

    public function reset(): void
    {
        $this->forget();
    }

    public function personId(): ?int
    {
        $id = $this->state()['person_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The draft currently being set up. A person who is no longer a draft is
     * never returned, so a stale session cannot reopen a completed profile.
     */
    public function person(): ?Personnel
    {
        $person = $this->personId() !== null ? Personnel::find($this->personId()) : null;

        if ($person === null || $person->status !== Personnel::STATUS_DRAFT) {
            return null;
        }

        return $person;
    }

    public function setPerson(Personnel $person): void
    {
        $this->put(['person_id' => $person->id]);
    }

    public function terminalId(): ?int
    {
        $id = $this->state()['terminal_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function terminal(): ?Terminal
    {
        return $this->terminalId() !== null ? Terminal::find($this->terminalId()) : null;
    }

    public function setTerminal(?Terminal $terminal): void
    {
        $this->put(['terminal_id' => $terminal?->id]);
    }

    public function rememberEnrollment(int $enrollmentId): void
    {
        $ids = $this->enrollmentIds();
        $ids[] = $enrollmentId;
        $this->put(['enrollment_ids' => array_values(array_unique($ids))]);
    }

    /** @return list<int> */
    public function enrollmentIds(): array
    {
        return array_values(array_map('intval', (array) ($this->state()['enrollment_ids'] ?? [])));
    }

    /** @return Collection<int, FingerprintEnrollment> */
    public function enrollments(): Collection
    {
        return FingerprintEnrollment::query()
            ->whereIn('id', $this->enrollmentIds())
            ->where('personnel_id', $this->personId())
            ->orderBy('id')
            ->get();
    }

    public function confirmedEnrollment(): ?FingerprintEnrollment
    {
        return $this->enrollments()
            ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)
            ->sortByDesc('id')
            ->first();
    }

    public function skipEnrollment(): void
    {
        $this->put(['skipped_enrollment' => true]);
    }

    public function enrollmentSkipped(): bool
    {
        return (bool) ($this->state()['skipped_enrollment'] ?? false);
    }

    /** @param list<int> $doorIds */
    public function setDoorIds(array $doorIds): void
    {
        $this->put(['door_ids' => array_values(array_map('intval', $doorIds))]);
    }

    /** @return list<int> */
    public function doorIds(): array
    {
        return array_values(array_map('intval', (array) ($this->state()['door_ids'] ?? [])));
    }

    public function complete(): void
    {
        $this->forget();
    }
}
