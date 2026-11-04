@php
    $steps = [
        1 => 'Details',
        2 => 'Device',
        3 => 'Fingerprint',
        4 => 'Doors',
        5 => 'Review',
        6 => 'Save & sync',
        7 => 'Verify',
    ];
@endphp

<div class="wizard-steps" aria-label="Onboarding progress">
    @foreach($steps as $number => $label)
        <span class="wizard-step {{ (int) $current === $number ? 'active' : ((int) $current > $number ? 'done' : '') }}"
              @if((int) $current === $number) aria-current="step" @endif>
            <span class="num">
                @if((int) $current > $number)
                    <i class="fa-solid fa-check" style="font-size:.55rem;"></i>
                @else
                    {{ $number }}
                @endif
            </span>
            <span>{{ $label }}</span>
        </span>
    @endforeach
</div>
