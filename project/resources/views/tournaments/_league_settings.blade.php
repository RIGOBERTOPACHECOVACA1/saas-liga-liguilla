@php
    $inputClass = 'w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border text-sm';
    $selectedTeams = collect(old('team_ids', $tournament->exists ? $tournament->teams->pluck('id')->all() : []))
        ->map(fn ($id) => (int) $id)
        ->all();
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label for="rounds" class="block text-sm font-medium text-gray-700 mb-1">Vueltas de la fase regular</label>
        <select id="rounds" name="rounds" class="{{ $inputClass }}">
            @foreach (\App\Models\Tournament::ROUNDS as $value => $label)
                <option value="{{ $value }}" @selected((int) old('rounds', $tournament->rounds ?? 1) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">1 vuelta: n(n-1)/2 partidos · 2 vueltas: n(n-1) partidos.</p>
    </div>
    <div id="playoff-teams-wrapper">
        <label for="playoff_teams" class="block text-sm font-medium text-gray-700 mb-1">Clasifican a la liguilla</label>
        <select id="playoff_teams" name="playoff_teams" class="{{ $inputClass }}">
            <option value="">Selecciona</option>
            @foreach (\App\Models\Tournament::PLAYOFF_TEAMS as $value => $label)
                <option value="{{ $value }}" @selected((int) old('playoff_teams', $tournament->playoff_teams) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">Solo para «Liga con liguilla». Partido único; en empate avanza el mejor de la tabla.</p>
    </div>
</div>

<fieldset>
    <legend class="block text-sm font-medium text-gray-700 mb-1">Equipos inscritos</legend>
    @if ($teams->isEmpty())
        <p class="text-sm text-gray-500">No hay equipos registrados.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 rounded-lg border border-gray-200 p-3 max-h-56 overflow-y-auto">
            @foreach ($teams as $team)
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="team_ids[]" value="{{ $team->id }}" class="rounded border-gray-300" @checked(in_array($team->id, $selectedTeams, true))>
                    {{ $team->name }}
                </label>
            @endforeach
        </div>
    @endif
</fieldset>

<script>
    (() => {
        const format = document.getElementById('format');
        const wrapper = document.getElementById('playoff-teams-wrapper');
        const sync = () => { wrapper.hidden = format.value !== 'league_playoff'; };
        format?.addEventListener('change', sync);
        if (format && wrapper) sync();
    })();
</script>
