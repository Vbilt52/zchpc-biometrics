@foreach(\App\Support\PersonnelListState::query(request(), request()->routeIs('personnel.index')) as $key => $value)
    <input type="hidden" name="list[{{ $key }}]" value="{{ $value }}">
@endforeach
