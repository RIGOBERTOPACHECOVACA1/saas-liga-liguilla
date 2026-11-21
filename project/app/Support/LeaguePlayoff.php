<?php

namespace App\Support;

use App\Models\Field;
use App\Models\Game;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Formato "Liga con liguilla": fase regular de todos contra todos y
 * clasificación de los mejores a una eliminatoria a partido único.
 */
class LeaguePlayoff
{
    /** Partidos que ya no se van a jugar y no bloquean el avance. */
    private const CLOSED_STATUSES = ['finished', 'cancelled'];

    /** Orden de las llaves para que 1 y 2 solo puedan verse en la final. */
    private const SEED_PAIRS = [
        2 => [[1, 2]],
        4 => [[1, 4], [2, 3]],
        8 => [[1, 8], [4, 5], [2, 7], [3, 6]],
    ];

    private const NEXT_STAGE = [
        'quarterfinal' => 'semifinal',
        'semifinal' => 'final',
    ];

    public function __construct(private Tournament $tournament)
    {
    }

    /**
     * Crea el calendario de la fase regular con el método del círculo.
     * Con n equipos: 1 vuelta = n(n-1)/2 partidos, 2 vueltas = n(n-1).
     */
    public function generateSchedule(): int
    {
        $teams = $this->tournament->teams()->orderBy('teams.id')->pluck('teams.id')->all();

        if (count($teams) < 2) {
            throw ValidationException::withMessages([
                'schedule' => 'El torneo necesita al menos 2 equipos inscritos.',
            ]);
        }

        if ($this->tournament->games()->exists()) {
            throw ValidationException::withMessages([
                'schedule' => 'El torneo ya tiene partidos. Elimínalos para volver a generar el calendario.',
            ]);
        }

        $fieldId = $this->defaultFieldId();
        $firstLeg = $this->roundRobin($teams);
        $matchdays = $firstLeg;

        if ((int) $this->tournament->rounds === 2) {
            foreach ($firstLeg as $pairs) {
                $matchdays[] = array_map(fn ($pair) => [$pair[1], $pair[0]], $pairs);
            }
        }

        $created = 0;

        DB::transaction(function () use ($matchdays, $fieldId, &$created) {
            foreach ($matchdays as $index => $pairs) {
                foreach ($pairs as [$local, $visitor]) {
                    $this->tournament->games()->create([
                        'local_team_id' => $local,
                        'visitor_team_id' => $visitor,
                        'field_id' => $fieldId,
                        'date_time' => $this->matchdayDate($index),
                        'local_goals' => 0,
                        'visitor_goals' => 0,
                        'matchday' => $index + 1,
                        'stage' => 'regular',
                        'status' => 'scheduled',
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Tabla general de la fase regular: victoria 3, empate 1, derrota 0.
     * Desempate: puntos, diferencia de goles, goles a favor y nombre.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function standings(): Collection
    {
        $rows = $this->tournament->teams()->orderBy('teams.name')->get()
            ->mapWithKeys(fn (Team $team) => [$team->id => [
                'team' => $team,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
            ]])
            ->all();

        $games = $this->tournament->games()
            ->where('stage', 'regular')
            ->where('status', 'finished')
            ->get();

        foreach ($games as $game) {
            if (! isset($rows[$game->local_team_id], $rows[$game->visitor_team_id])) {
                continue;
            }

            $this->addResult($rows[$game->local_team_id], $game->local_goals, $game->visitor_goals);
            $this->addResult($rows[$game->visitor_team_id], $game->visitor_goals, $game->local_goals);
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['goal_difference'] = $row['goals_for'] - $row['goals_against'];
                $row['points'] = $row['won'] * 3 + $row['drawn'];

                return $row;
            })
            ->sort(fn (array $a, array $b) => [$b['points'], $b['goal_difference'], $b['goals_for'], $a['team']->name]
                <=> [$a['points'], $a['goal_difference'], $a['goals_for'], $b['team']->name])
            ->values()
            ->map(fn (array $row, int $index) => $row + ['position' => $index + 1]);
    }

    public function regularPhaseFinished(): bool
    {
        $regular = $this->tournament->games()->where('stage', 'regular');

        return $regular->exists()
            && ! $regular->whereNotIn('status', self::CLOSED_STATUSES)->exists();
    }

    /**
     * Partidos de liguilla agrupados por ronda, en orden de llave.
     *
     * @return array<string, Collection<int, Game>>
     */
    public function bracket(): array
    {
        $games = $this->tournament->games()
            ->with(['localTeam', 'visitorTeam'])
            ->where('stage', '!=', 'regular')
            ->orderBy('bracket_slot')
            ->get()
            ->groupBy('stage');

        $bracket = [];

        foreach (['quarterfinal', 'semifinal', 'final'] as $stage) {
            if ($games->has($stage)) {
                $bracket[$stage] = $games[$stage];
            }
        }

        return $bracket;
    }

    /** Siguiente paso posible de la liguilla, o null si no hay nada que hacer. */
    public function nextPlayoffAction(): ?string
    {
        if (! $this->tournament->hasPlayoff() || ! $this->regularPhaseFinished()) {
            return null;
        }

        $bracket = $this->bracket();

        if ($bracket === []) {
            return 'start';
        }

        $stage = array_key_last($bracket);

        if ($stage === 'final' || ! $this->stageFinished($bracket[$stage])) {
            return null;
        }

        return 'advance';
    }

    /**
     * Genera la primera ronda de liguilla o la siguiente según los ganadores.
     */
    public function advancePlayoff(): string
    {
        if (! $this->tournament->hasPlayoff()) {
            throw ValidationException::withMessages([
                'playoff' => 'Este torneo no tiene liguilla.',
            ]);
        }

        if (! $this->regularPhaseFinished()) {
            throw ValidationException::withMessages([
                'playoff' => 'Primero deben terminar todos los partidos de la fase regular.',
            ]);
        }

        $bracket = $this->bracket();

        return $bracket === [] ? $this->startPlayoff() : $this->nextRound($bracket);
    }

    /** Equipo que gana una llave; en empate avanza el mejor sembrado. */
    public function winner(Game $game): ?Team
    {
        if ($game->status !== 'finished') {
            return null;
        }

        if ($game->local_goals !== $game->visitor_goals) {
            return $game->local_goals > $game->visitor_goals ? $game->localTeam : $game->visitorTeam;
        }

        $seeds = $this->seeds();

        return ($seeds[$game->local_team_id] ?? PHP_INT_MAX) <= ($seeds[$game->visitor_team_id] ?? PHP_INT_MAX)
            ? $game->localTeam
            : $game->visitorTeam;
    }

    public function champion(): ?Team
    {
        $final = $this->bracket()['final'] ?? null;

        return $final ? $this->winner($final->first()) : null;
    }

    /**
     * Lugar de cada equipo en la tabla general (1 = líder).
     *
     * @return array<int, int>
     */
    public function seeds(): array
    {
        return $this->standings()
            ->mapWithKeys(fn (array $row) => [$row['team']->id => $row['position']])
            ->all();
    }

    private function startPlayoff(): string
    {
        $size = (int) $this->tournament->playoff_teams;

        if (! isset(self::SEED_PAIRS[$size])) {
            throw ValidationException::withMessages([
                'playoff' => 'Configura cuántos equipos clasifican a la liguilla.',
            ]);
        }

        $qualified = $this->standings()->take($size)->pluck('team.id')->values();

        if ($qualified->count() < $size) {
            throw ValidationException::withMessages([
                'playoff' => "Se necesitan al menos {$size} equipos para la liguilla.",
            ]);
        }

        $stage = match ($size) {
            8 => 'quarterfinal',
            4 => 'semifinal',
            default => 'final',
        };

        $pairs = array_map(
            fn (array $seeds) => [$qualified[$seeds[0] - 1], $qualified[$seeds[1] - 1]],
            self::SEED_PAIRS[$size],
        );

        $this->createRound($stage, $pairs);

        return $stage;
    }

    /**
     * @param  array<string, Collection<int, Game>>  $bracket
     */
    private function nextRound(array $bracket): string
    {
        $stage = array_key_last($bracket);

        if ($stage === 'final') {
            throw ValidationException::withMessages([
                'playoff' => 'La liguilla ya terminó.',
            ]);
        }

        if (! $this->stageFinished($bracket[$stage])) {
            throw ValidationException::withMessages([
                'playoff' => 'Faltan partidos por terminar en la ronda actual.',
            ]);
        }

        $seeds = $this->seeds();
        $winners = $bracket[$stage]->map(fn (Game $game) => $this->winner($game)->id)->values();
        $pairs = [];

        foreach ($winners->chunk(2) as $pair) {
            // El mejor sembrado juega como local.
            $pairs[] = collect($pair)->sortBy(fn (int $id) => $seeds[$id] ?? PHP_INT_MAX)->values()->all();
        }

        $next = self::NEXT_STAGE[$stage];
        $this->createRound($next, $pairs);

        return $next;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $pairs
     */
    private function createRound(string $stage, array $pairs): void
    {
        $fieldId = $this->defaultFieldId();
        $lastDate = $this->tournament->games()->max('date_time');
        $date = $lastDate ? Carbon::parse($lastDate)->addWeek() : $this->matchdayDate(0);

        DB::transaction(function () use ($stage, $pairs, $fieldId, $date) {
            foreach ($pairs as $slot => [$local, $visitor]) {
                $this->tournament->games()->create([
                    'local_team_id' => $local,
                    'visitor_team_id' => $visitor,
                    'field_id' => $fieldId,
                    'date_time' => $date,
                    'local_goals' => 0,
                    'visitor_goals' => 0,
                    'stage' => $stage,
                    'bracket_slot' => $slot,
                    'status' => 'scheduled',
                ]);
            }
        });
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    private function stageFinished(Collection $games): bool
    {
        return $games->every(fn (Game $game) => $game->status === 'finished');
    }

    /**
     * Método del círculo: el primer equipo queda fijo y los demás rotan.
     * Con número impar se agrega un "descanso" (null).
     *
     * @param  array<int, int>  $teams
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    private function roundRobin(array $teams): array
    {
        if (count($teams) % 2 === 1) {
            $teams[] = null;
        }

        $count = count($teams);
        $matchdays = [];

        for ($round = 0; $round < $count - 1; $round++) {
            $pairs = [];

            for ($i = 0; $i < $count / 2; $i++) {
                $home = $teams[$i];
                $away = $teams[$count - 1 - $i];

                if ($home === null || $away === null) {
                    continue;
                }

                // Alterna la localía del equipo fijo para repartirla.
                $pairs[] = ($i === 0 && $round % 2 === 1) ? [$away, $home] : [$home, $away];
            }

            $matchdays[] = $pairs;
            $teams = [$teams[0], $teams[$count - 1], ...array_slice($teams, 1, $count - 2)];
        }

        return $matchdays;
    }

    /**
     * @param  array<string, int>  $row
     */
    private function addResult(array &$row, int $goalsFor, int $goalsAgainst): void
    {
        $row['played']++;
        $row['goals_for'] += $goalsFor;
        $row['goals_against'] += $goalsAgainst;

        match ($goalsFor <=> $goalsAgainst) {
            1 => $row['won']++,
            0 => $row['drawn']++,
            -1 => $row['lost']++,
        };
    }

    private function matchdayDate(int $index): Carbon
    {
        $start = $this->tournament->start_date
            ? Carbon::parse($this->tournament->start_date)
            : Carbon::today();

        return $start->copy()->setTime(16, 0)->addWeeks($index);
    }

    private function defaultFieldId(): int
    {
        $fieldId = DB::table('tournament_fields')
            ->where('tournament_id', $this->tournament->id)
            ->orderBy('id')
            ->value('field_id')
            ?? Field::query()->orderBy('id')->value('id');

        if (! $fieldId) {
            throw ValidationException::withMessages([
                'schedule' => 'Registra al menos una sede antes de generar partidos.',
            ]);
        }

        return (int) $fieldId;
    }
}
