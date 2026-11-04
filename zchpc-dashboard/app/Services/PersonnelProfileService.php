<?php

namespace App\Services;

use App\Models\AccessGroup;
use App\Models\Personnel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared profile rules for onboarding and editing.
 *
 * Employee numbers are immutable once a credential exists on a controller:
 * both the device identity and the access history are keyed by them.
 */
class PersonnelProfileService
{
    public function __construct(
        private readonly PersonnelPhotoService $photos,
        private readonly AccessAuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function rules(?Personnel $personnel = null): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'employee_no' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('personnel', 'employee_no')->ignore($personnel?->id),
            ],
            'department' => ['required', 'integer', 'exists:access_groups,id'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'user_type' => ['required', Rule::in(['normal', 'visitor'])],
            'begin_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:begin_time'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('access.photos.max_kilobytes', 2048)],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * People who may be the same human. The employee number remains a hard
     * uniqueness rule; this is the softer warning the administrator confirms.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Personnel>
     */
    public function duplicates(array $data, ?int $ignoreId = null): Collection
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone_number'] ?? ''));

        if ($name === '' && $email === '' && $phone === '') {
            return collect();
        }

        return Personnel::query()
            ->onboarded()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($name, $email, $phone): void {
                if ($name !== '') {
                    $query->where('name', 'like', '%'.$name.'%');
                }
                if ($email !== '') {
                    $query->orWhere('email', $email);
                }
                if ($phone !== '') {
                    $query->orWhere('phone_number', $phone);
                }
            })
            ->limit(5)
            ->get(['id', 'name', 'employee_no', 'department']);
    }

    public function createDraft(array $data, ?UploadedFile $photo, ?User $actor = null, ?string $ipAddress = null): Personnel
    {
        $group = AccessGroup::findOrFail($data['department']);
        $zone = config('attendance.timezone');

        $personnel = Personnel::query()->create([
            'employee_no' => $data['employee_no'],
            'name' => $data['name'],
            'user_type' => $data['user_type'],
            'valid_enabled' => true,
            'begin_time' => Carbon::parse($data['begin_time'], $zone)->utc(),
            'end_time' => Carbon::parse($data['end_time'], $zone)->utc(),
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'department' => $group->name,
            'access_group_id' => $group->id,
            'door_right' => null,
            'right_plan' => null,
            'status' => Personnel::STATUS_DRAFT,
            'added_at' => now(),
        ]);

        if ($photo !== null) {
            $personnel->forceFill(['photo_path' => $this->photos->store($photo, $personnel)])->save();
        }

        $this->audit->log('user_created', 'info',
            'Started onboarding for '.$personnel->name.' ('.$personnel->employee_no.').',
            ['employee_no' => $personnel->employee_no], $actor, $personnel, ipAddress: $ipAddress);

        return $personnel;
    }

    public function updateProfile(Personnel $personnel, array $data, ?UploadedFile $photo, ?User $actor = null, ?string $ipAddress = null): Personnel
    {
        if ($data['employee_no'] !== $personnel->employee_no
            && ($personnel->fingerprintEnrollments()->where('status', 'confirmed')->exists()
                || $personnel->terminals()->exists())) {
            throw ValidationException::withMessages([
                'employee_no' => 'Employee ID is linked to device credentials and access history. Keep this ID and edit the other details instead.',
            ]);
        }

        $group = AccessGroup::findOrFail($data['department']);
        $zone = config('attendance.timezone');

        $personnel->fill([
            'employee_no' => $data['employee_no'],
            'name' => $data['name'],
            'user_type' => $data['user_type'],
            'begin_time' => Carbon::parse($data['begin_time'], $zone)->utc(),
            'end_time' => Carbon::parse($data['end_time'], $zone)->utc(),
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'department' => $group->name,
            'access_group_id' => $group->id,
        ]);

        if ($photo !== null) {
            $personnel->photo_path = $this->photos->store($photo, $personnel);
        }

        $changed = array_keys($personnel->getDirty());
        $personnel->save();

        $this->audit->log('user_updated', 'info',
            'Profile details updated for '.$personnel->name.'.',
            ['fields' => $changed], $actor, $personnel, ipAddress: $ipAddress);

        return $personnel;
    }
}
