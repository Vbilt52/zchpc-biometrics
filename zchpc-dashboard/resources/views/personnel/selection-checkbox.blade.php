<td style="width:42px;text-align:center;">
    <input type="checkbox" name="ids[]" value="{{ $person->id }}" form="bulk-delete-form"
           data-person-selection data-person-label="{{ $person->name }} ({{ $person->employee_no }})"
           aria-label="Select {{ $person->name }} ({{ $person->employee_no }})" style="width:18px;height:18px;cursor:pointer;">
</td>
