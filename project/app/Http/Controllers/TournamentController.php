<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\League;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TournamentController extends Controller
{
    public function index()
    {
        $tournaments = Tournament::with('league')->latest()->paginate(10);
        return view('tournaments.index', compact('tournaments'));
    }

    public function create()
    {
        $leagues = League::all();
        $statuses = Tournament::STATUSES;
        $teams = Team::query()->orderBy('name')->get();
        return view('tournaments.create', compact('leagues', 'statuses', 'teams'));
    }

    public function store(Request $request)
    {
        $validated = $this->validatedData($request);

        $teamIds = $validated['team_ids'];
        unset($validated['team_ids']);

        $tournament = Tournament::create($validated);
        $tournament->teams()->sync($teamIds);

        return redirect()->route('tournaments.index')
                         ->with('success', 'Torneo creado con éxito.');
    }

    public function show(Tournament $tournament)
    {
        $tournament->load(['league', 'games']);
        return view('tournaments.show', compact('tournament'));
    }

    public function edit(Tournament $tournament)
    {
        $leagues = League::all();
        $statuses = Tournament::STATUSES;
        $teams = Team::query()->orderBy('name')->get();
        $tournament->load('teams');
        return view('tournaments.edit', compact('tournament', 'leagues', 'statuses', 'teams'));
    }

    public function update(Request $request, Tournament $tournament)
    {
        $validated = $this->validatedData($request);

        $teamIds = $validated['team_ids'];
        unset($validated['team_ids']);

        $tournament->update($validated);
        $tournament->teams()->sync($teamIds);

        return redirect()->route('tournaments.index')
                         ->with('success', 'Torneo actualizado correctamente.');
    }

    public function destroy(Request $request, Tournament $tournament = null)
    {
        // 1. Si se seleccionaron múltiples torneos con los checkboxes
        if ($request->has('ids') && is_array($request->ids)) {
            Tournament::whereIn('id', $request->ids)->delete();

            return redirect()->route('tournaments.index')
                             ->with('success', 'Torneos seleccionados eliminados correctamente.');
        }

        // 2. Si es una eliminación individual mediante inyección de modelos
        if ($tournament && $tournament->exists) {
            $tournament->delete();

            return redirect()->route('tournaments.index')
                             ->with('success', 'Torneo eliminado correctamente.');
        }

        return redirect()->route('tournaments.index')
                         ->with('error', 'No se encontró ningún torneo para eliminar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $validated = $request->validate([
            'league_id'     => ['required', 'exists:leagues,id'],
            'name'          => ['required', 'string', 'max:255'],
            'season'        => ['nullable', 'string', 'max:50'],
            'start_date'    => ['nullable', 'date'],
            'end_date'      => ['nullable', 'date', 'after_or_equal:start_date'],
            'format'        => ['nullable', Rule::in(array_keys(Tournament::FORMATS))],
            'rounds'        => ['required', Rule::in(array_keys(Tournament::ROUNDS))],
            'playoff_teams' => ['nullable', 'required_if:format,league_playoff', Rule::in(array_keys(Tournament::PLAYOFF_TEAMS))],
            'team_ids'      => ['nullable', 'array'],
            'team_ids.*'    => ['integer', 'distinct', 'exists:teams,id'],
            'status'        => ['required', Rule::in(array_keys(Tournament::STATUSES))],
        ], [
            'format.in'                 => 'El formato no es válido.',
            'rounds.required'           => 'Elige cuántas vueltas tiene la fase regular.',
            'rounds.in'                 => 'Las vueltas deben ser 1 o 2.',
            'playoff_teams.required_if' => 'Elige cuántos equipos clasifican a la liguilla.',
            'playoff_teams.in'          => 'Clasifican 2, 4 u 8 equipos.',
            'team_ids.*.exists'         => 'Uno de los equipos no existe.',
        ]);

        $validated['team_ids'] = array_map('intval', $validated['team_ids'] ?? []);

        if (($validated['format'] ?? null) !== 'league_playoff') {
            $validated['playoff_teams'] = null;
        } elseif (count($validated['team_ids']) < (int) $validated['playoff_teams']) {
            throw ValidationException::withMessages([
                'playoff_teams' => 'Inscribe al menos tantos equipos como los que clasifican a la liguilla.',
            ]);
        }

        return $validated;
    }
}
