<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessEvent extends Model
{
    public const DENIED_MINOR_CODES = [2, 3, 22, 39, 40, 76];

    public function scopeFailedAttempt(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('event_type', 'AccessDenied')->orWhere(function (Builder $query): void {
                $query->where('major', 5)->whereIn('minor', self::DENIED_MINOR_CODES);
            });
        });
    }

    public function isFailedAttempt(): bool
    {
        return $this->event_type === 'AccessDenied'
            || ((int) $this->major === 5 && in_array((int) $this->minor, self::DENIED_MINOR_CODES, true));
    }

    protected $fillable = [
        'terminal_id',
        'event_key',
        'employee_no',
        'person_name',
        'occurred_at',
        'major',
        'minor',
        'verify_mode',
        'event_type',
        'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'major' => 'integer',
        'minor' => 'integer',
        'raw_payload' => 'array',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'employee_no', 'employee_no');
    }

    public function isSuccessfulFingerprint(): bool
    {
        if ($this->employee_no === null || trim($this->employee_no) === ''
            || ($this->major !== null && $this->major !== 5)
            || $this->event_type === 'AccessDenied'
            || in_array($this->minor, [2, 3, 22, 39, 40, 76], true)) {
            return false;
        }

        $method = strtolower(preg_replace('/[^a-z]/i', '', (string) $this->verify_mode));
        $fingerprint = $this->minor === 38
            || in_array($method, ['fingerprint', 'fingerprintpin', 'fingerprintandpin'], true);
        $granted = ($this->major === 5 && $this->minor === 38)
            || $this->event_type === 'AccessGranted';

        return $fingerprint && $granted;
    }

    public function methodLabel(): string
    {
        $minor = (int) ($this->minor ?? 0);
        $method = strtolower((string) ($this->verify_mode ?? ''));

        if (in_array($minor, [38, 39, 40, 41, 42, 25, 26, 27], true)) {
            return 'Fingerprint';
        }

        if (in_array($minor, [75, 76, 77, 78, 79, 65, 66], true)) {
            return 'Face';
        }

        if (in_array($minor, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20], true)) {
            return 'Card';
        }

        if (in_array($minor, [21, 22, 23, 24], true)) {
            return 'PIN';
        }

        if ($minor === 28) {
            return 'Exit Button';
        }

        return match ($method) {
            'fingerprint' => 'Fingerprint',
            'fingerprint+pin' => 'Fingerprint + PIN',
            'pin' => 'PIN',
            'card' => 'Card',
            'cardorpin' => 'Card / PIN',
            '' => 'System',
            default => ucfirst($method),
        };
    }

    public function outcomeLabel(): string
    {
        if ($this->isFailedAttempt()) {
            return 'Denied';
        }
        $minor = (int) ($this->minor ?? 0);

        if (in_array($minor, [1, 21, 25, 26, 27, 28, 38, 65, 75], true)
            || $this->event_type === 'AccessGranted') {
            return $minor === 28 ? 'Exit' : 'Granted';
        }

        return 'Info';
    }
}
