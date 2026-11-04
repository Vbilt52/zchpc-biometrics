<?php

namespace App\Http\Controllers;

use App\Models\AccessGroup;
use App\Models\Door;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccessGroupController extends Controller
{
    public function index(): View
    {
        $groups = AccessGroup::withCount(['personnel', 'doors'])->orderBy('name')->get();

        return view('access-groups.index', compact('groups'));
    }

    public function create(): View
    {
        return view('access-groups.create', ['doors' => $this->doorChoices()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:access_groups,name'],
            'door_ids' => ['nullable', 'array', 'max:100'],
            'door_ids.*' => ['integer', 'distinct', 'exists:doors,id'],
        ]);

        $group = AccessGroup::create(['name' => $validated['name']]);
        $group->doors()->sync(array_map('intval', $validated['door_ids'] ?? []));

        return redirect()->route('access-groups.index')->with('success', 'Access group created.');
    }

    public function edit(AccessGroup $accessGroup): View
    {
        $accessGroup->load('doors');

        return view('access-groups.edit', [
            'accessGroup' => $accessGroup,
            'doors' => $this->doorChoices(),
            'selectedDoorIds' => $accessGroup->doors->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, AccessGroup $accessGroup): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('access_groups')->ignore($accessGroup->id)],
            'door_ids' => ['nullable', 'array', 'max:100'],
            'door_ids.*' => ['integer', 'distinct', 'exists:doors,id'],
        ]);

        $accessGroup->update(['name' => $validated['name']]);
        $accessGroup->personnel()->update(['department' => $accessGroup->name]);
        $accessGroup->doors()->sync(array_map('intval', $validated['door_ids'] ?? []));

        return redirect()->route('access-groups.index')->with('success', 'Access group updated.');
    }

    /** @return Collection<string, Collection<int, Door>> */
    private function doorChoices(): Collection
    {
        return Door::query()->with('terminal')
            ->orderBy('building')->orderBy('floor')->orderBy('name')
            ->get()
            ->groupBy(fn (Door $door): string => $door->building ?: 'Unassigned building');
    }

    public function destroy(AccessGroup $accessGroup): RedirectResponse
    {
        if ($accessGroup->personnel()->exists()) {
            return back()->with('error', 'Reassign the people in this access group before deleting it.');
        }

        $accessGroup->delete();

        return redirect()->route('access-groups.index')->with('success', 'Access group deleted.');
    }
}
