@extends('layouts.crud')

@section('title', $tournament->name)
@section('subtitle', $tournament->formatLabel())
@section('module', $tournament->name)

@section('actions')
    @if ($regularGames->isEmpty() && $tournament->isRoundRobin())
        <form method="POST" action="{{ route('tournaments.schedule', $tournament) }}">
            @csrf
            <button type="submit" class="inline-flex cursor-pointer items-center justify-center rounded-lg border-0 bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                Generar calendario
            </button>
        </form>
    @endif

    @if ($nextAction)
        <form method="POST" action="{{ route('tournaments.playoff', $tournament) }}">
            @csrf
            <button type="submit" class="inline-flex cursor-pointer items-center justify-center rounded-lg border-0 bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                {{ $nextAction === 'start' ? 'Generar liguilla' : 'Siguiente ronda' }}
            </button>
        </form>
    @endif

    <a href="{{ route('tournaments.edit', $tournament) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 no-underline hover:bg-gray-50">
        Editar torneo
    </a>
@endsection

@section('content')
    <div class="mb-6 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600">
        <span><strong class="text-gray-800">Liga:</strong> {{ $tournament->league->name ?? '—' }}</span>
        <span><strong class="text-gray-800">Equipos:</strong> {{ $tournament->teams->count() }}</span>
        <span><strong class="text-gray-800">Vueltas:</strong> {{ \App\Models\Tournament::ROUNDS[$tournament->rounds] ?? $tournament->rounds }}</span>
        @if ($tournament->hasPlayoff())
            <span><strong class="text-gray-800">Liguilla:</strong> {{ \App\Models\Tournament::PLAYOFF_TEAMS[$tournament->playoff_teams] ?? '—' }}</span>
        @endif
    </div>

    @unless ($tournament->isRoundRobin())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Este torneo no tiene formato de liga. Edítalo y elige «Liga» o «Liga con liguilla» para generar el calendario.
        </div>
    @endunless

    @if ($champion)
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-5 text-center">
            <p class="m-0 text-xs font-semibold uppercase tracking-wide text-amber-700">Campeón</p>
            <p class="m-0 mt-1 text-2xl font-bold text-gray-900">🏆 {{ $champion->name }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-8 xl:grid-cols-2">
        <section>
            <h2 class="mb-3 text-base font-semibold text-gray-800">Tabla general</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-2 py-2">#</th>
                            <th class="px-2 py-2">Equipo</th>
                            <th class="px-2 py-2 text-center" title="Partidos jugados">PJ</th>
                            <th class="px-2 py-2 text-center" title="Ganados">G</th>
                            <th class="px-2 py-2 text-center" title="Empatados">E</th>
                            <th class="px-2 py-2 text-center" title="Perdidos">P</th>
                            <th class="px-2 py-2 text-center" title="Goles a favor">GF</th>
                            <th class="px-2 py-2 text-center" title="Goles en contra">GC</th>
                            <th class="px-2 py-2 text-center" title="Diferencia de goles">DG</th>
                            <th class="px-2 py-2 text-center">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($standings as $row)
                            @php($qualified = in_array($row['team']->id, $qualifiedIds, true))
                            <tr class="border-b border-gray-100 {{ $qualified ? 'bg-emerald-50' : '' }}">
                                <td class="px-2 py-2 font-semibold {{ $qualified ? 'text-emerald-700' : 'text-gray-500' }}">{{ $row['position'] }}</td>
                                <td class="px-2 py-2 font-medium text-gray-800">{{ $row['team']->name }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['played'] }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['won'] }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['drawn'] }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['lost'] }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['goals_for'] }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['goals_against'] }}</td>
                                <td class="px-2 py-2 text-center text-gray-600">{{ $row['goal_difference'] > 0 ? '+' : '' }}{{ $row['goal_difference'] }}</td>
                                <td class="px-2 py-2 text-center font-bold text-gray-900">{{ $row['points'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-2 py-6 text-center text-gray-500">Inscribe equipos en el torneo para ver la tabla.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tournament->hasPlayoff() && $qualifiedIds)
                <p class="mt-2 text-xs text-gray-500"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-200 align-middle"></span> Zona de liguilla. Victoria 3 pts, empate 1. Desempate: diferencia de goles y goles a favor.</p>
            @endif
        </section>

        @if ($tournament->hasPlayoff())
            <section>
                <h2 class="mb-3 text-base font-semibold text-gray-800">Liguilla</h2>
                @if ($bracket === [])
                    <p class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                        Cuando terminen todos los partidos de la fase regular podrás generar la liguilla con los {{ $tournament->playoff_teams }} mejores de la tabla.
                    </p>
                @else
                    <div class="flex gap-4 overflow-x-auto pb-2">
                        @foreach ($bracket as $stage => $games)
                            <div class="flex min-w-48 flex-1 flex-col justify-around gap-3">
                                <h3 class="m-0 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $stages[$stage] }}</h3>
                                @foreach ($games as $game)
                                    @php($winner = $league->winner($game))
                                    <a href="{{ route('matches.edit', $game) }}" class="block rounded-lg border border-gray-200 bg-white text-sm no-underline shadow-sm hover:border-blue-400">
                                        @foreach ([[$game->localTeam, $game->local_goals], [$game->visitorTeam, $game->visitor_goals]] as [$team, $goals])
                                            <div class="flex items-center justify-between gap-2 px-3 py-2 {{ $loop->first ? 'border-b border-gray-100' : '' }} {{ $winner?->is($team) ? 'font-semibold text-gray-900' : 'text-gray-600' }}">
                                                <span>{{ $team->name }}</span>
                                                <span>{{ $game->status === 'finished' ? $goals : '–' }}</span>
                                            </div>
                                        @endforeach
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Partido único. En empate avanza el equipo mejor ubicado en la tabla. Haz clic en un partido para capturar el resultado.</p>
                @endif
            </section>
        @endif
    </div>

    <section class="mt-8">
        <h2 class="mb-3 text-base font-semibold text-gray-800">Calendario de la fase regular</h2>
        @forelse ($regularGames as $matchday => $games)
            <div class="mb-4">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Jornada {{ $matchday }}</h3>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($games as $game)
                        <a href="{{ route('matches.edit', $game) }}" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm no-underline hover:border-blue-400">
                            <span class="text-gray-800">{{ $game->localTeam->name }} <span class="text-gray-400">vs</span> {{ $game->visitorTeam->name }}</span>
                            <span class="shrink-0 {{ $game->status === 'finished' ? 'font-semibold text-gray-900' : 'text-xs text-gray-500' }}">
                                {{ $game->status === 'finished' ? $game->local_goals.' - '.$game->visitor_goals : $game->statusLabel() }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                Aún no hay calendario. Inscribe equipos y presiona «Generar calendario».
            </p>
        @endforelse
    </section>
@endsection
