<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Language;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeaguePlayoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaguePlayoffTest extends TestCase
{
    use RefreshDatabase;

    private int $leagueId;

    private int $localityId;

    private int $teamNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $countryId = DB::table('countries')->insertGetId(['name' => 'México', 'iso2_code' => 'MX', 'iso3_code' => 'MEX']);
        $currencyId = DB::table('currencies')->insertGetId(['name' => 'Peso', 'code' => 'MXN', 'symbol' => '$']);
        $adminDivisionId = DB::table('admin_divisions')->insertGetId(['country_id' => $countryId, 'name' => 'Jalisco']);
        $municipalityId = DB::table('municipalities')->insertGetId(['admin_division_id' => $adminDivisionId, 'name' => 'Guadalajara']);
        $this->localityId = DB::table('localities')->insertGetId(['municipality_id' => $municipalityId, 'name' => 'Centro']);

        $addressId = DB::table('addresses')->insertGetId([
            'name' => 'Estadio Demo',
            'country_id' => $countryId,
            'admin_division_id' => $adminDivisionId,
            'municipality_id' => $municipalityId,
            'locality_id' => $this->localityId,
            'line_1' => 'Av. del Deporte 123',
        ]);
        DB::table('fields')->insert(['address_id' => $addressId, 'name' => 'Cancha 1', 'status' => 'available']);

        $this->leagueId = DB::table('leagues')->insertGetId([
            'name' => 'Liga Demo',
            'slug' => 'liga-demo',
            'country_id' => $countryId,
            'currency_id' => $currencyId,
        ]);
    }

    public function test_un_invitado_no_entra_a_la_liga(): void
    {
        $tournament = $this->createTournament(4);

        $this->get(route('tournaments.league', $tournament))->assertRedirect(route('login'));
    }

    public function test_se_crea_un_torneo_con_liguilla_y_equipos_inscritos(): void
    {
        $teams = $this->createTeams(4);

        $this->actingAs($this->createUser())
            ->post(route('tournaments.store'), [
                'league_id' => $this->leagueId,
                'name' => 'Apertura 2026',
                'format' => 'league_playoff',
                'rounds' => 2,
                'playoff_teams' => 4,
                'team_ids' => $teams,
                'status' => 'active',
            ])
            ->assertRedirect(route('tournaments.index'));

        $tournament = Tournament::query()->where('name', 'Apertura 2026')->firstOrFail();

        $this->assertSame('league_playoff', $tournament->format);
        $this->assertSame(2, $tournament->rounds);
        $this->assertSame(4, $tournament->playoff_teams);
        $this->assertEqualsCanonicalizing($teams, $tournament->teams()->pluck('teams.id')->all());
    }

    public function test_la_liguilla_requiere_clasificados_y_suficientes_equipos(): void
    {
        $user = $this->createUser();
        $payload = [
            'league_id' => $this->leagueId,
            'name' => 'Apertura 2026',
            'format' => 'league_playoff',
            'rounds' => 1,
            'team_ids' => $this->createTeams(3),
            'status' => 'active',
        ];

        $this->actingAs($user)
            ->from(route('tournaments.create'))
            ->post(route('tournaments.store'), $payload)
            ->assertSessionHasErrors(['playoff_teams' => 'Elige cuántos equipos clasifican a la liguilla.']);

        $this->actingAs($user)
            ->from(route('tournaments.create'))
            ->post(route('tournaments.store'), $payload + ['playoff_teams' => 4])
            ->assertSessionHasErrors(['playoff_teams' => 'Inscribe al menos tantos equipos como los que clasifican a la liguilla.']);

        $this->assertDatabaseCount('tournaments', 0);
    }

    public function test_una_vuelta_genera_n_por_n_menos_uno_entre_dos_partidos(): void
    {
        $tournament = $this->createTournament(6, rounds: 1);

        $this->actingAs($this->createUser())
            ->post(route('tournaments.schedule', $tournament))
            ->assertRedirect(route('tournaments.league', $tournament))
            ->assertSessionHas('status', 'Calendario generado: 15 partidos.');

        $games = $tournament->games()->get();

        $this->assertCount(15, $games);
        $this->assertSame(5, $games->max('matchday'));
        $this->assertEveryPairPlaysTimes($games, 1);

        // En cada jornada ningún equipo juega dos veces.
        foreach ($games->groupBy('matchday') as $matchday) {
            $teams = $matchday->flatMap(fn (Game $game) => [$game->local_team_id, $game->visitor_team_id]);
            $this->assertSame($teams->count(), $teams->unique()->count());
        }
    }

    public function test_dos_vueltas_genera_n_por_n_menos_uno_partidos_con_localia_invertida(): void
    {
        $tournament = $this->createTournament(5, rounds: 2);

        $created = (new LeaguePlayoff($tournament))->generateSchedule();
        $games = $tournament->games()->get();

        $this->assertSame(20, $created);
        $this->assertSame(10, $games->max('matchday'));
        $this->assertEveryPairPlaysTimes($games, 2);

        foreach ($games as $game) {
            $this->assertTrue($games->contains(fn (Game $other) => $other->local_team_id === $game->visitor_team_id
                && $other->visitor_team_id === $game->local_team_id));
        }
    }

    public function test_no_se_genera_el_calendario_dos_veces(): void
    {
        $tournament = $this->createTournament(4);
        $user = $this->createUser();

        $this->actingAs($user)->post(route('tournaments.schedule', $tournament));

        $this->actingAs($user)
            ->from(route('tournaments.league', $tournament))
            ->post(route('tournaments.schedule', $tournament))
            ->assertSessionHasErrors('schedule');

        $this->assertSame(6, $tournament->games()->count());
    }

    public function test_la_tabla_suma_puntos_y_desempata_por_diferencia_de_goles(): void
    {
        $tournament = $this->createTournament(3);
        [$a, $b, $c] = $tournament->teams()->orderBy('teams.id')->pluck('teams.id')->all();

        $this->playGame($tournament, $a, $b, 3, 0);
        $this->playGame($tournament, $b, $c, 1, 1);
        $this->playGame($tournament, $c, $a, 2, 1);
        // Un partido sin terminar no cuenta.
        $this->playGame($tournament, $a, $c, 5, 0, 'scheduled');

        $standings = (new LeaguePlayoff($tournament))->standings();

        $this->assertSame([$c, $a, $b], $standings->pluck('team.id')->all());
        $this->assertSame([4, 3, 1], $standings->pluck('points')->all());
        $this->assertSame([2, 2, 2], $standings->pluck('played')->all());
        $this->assertSame([1, 2, -3], $standings->pluck('goal_difference')->all());

        $this->actingAs($this->createUser())
            ->get(route('tournaments.league', $tournament))
            ->assertOk()
            ->assertSee('Tabla general')
            ->assertSee('Liguilla');
    }

    public function test_la_liguilla_cruza_uno_contra_cuatro_y_dos_contra_tres_y_corona_campeon(): void
    {
        $tournament = $this->createTournament(5, playoffTeams: 4);
        $user = $this->createUser();
        $league = new LeaguePlayoff($tournament);

        $league->generateSchedule();
        $this->assertNull($league->nextPlayoffAction());

        // Fase regular: gana siempre el equipo con id menor, así la tabla queda 1..5 por id.
        $tournament->games()->each(fn (Game $game) => $game->update([
            'local_goals' => $game->local_team_id < $game->visitor_team_id ? 1 : 0,
            'visitor_goals' => $game->local_team_id < $game->visitor_team_id ? 0 : 1,
            'status' => 'finished',
        ]));

        $seeds = $league->standings()->pluck('team.id')->all();
        $this->assertSame('start', $league->nextPlayoffAction());

        $this->actingAs($user)
            ->post(route('tournaments.playoff', $tournament))
            ->assertSessionHas('status', 'Semifinal generada.');

        $semis = $tournament->games()->where('stage', 'semifinal')->orderBy('bracket_slot')->get();
        $this->assertSame([$seeds[0], $seeds[3]], [$semis[0]->local_team_id, $semis[0]->visitor_team_id]);
        $this->assertSame([$seeds[1], $seeds[2]], [$semis[1]->local_team_id, $semis[1]->visitor_team_id]);

        // Semifinal 1 empatada: avanza el mejor sembrado. Semifinal 2: gana el visitante.
        $semis[0]->update(['local_goals' => 2, 'visitor_goals' => 2, 'status' => 'finished']);
        $semis[1]->update(['local_goals' => 0, 'visitor_goals' => 1, 'status' => 'finished']);

        $this->actingAs($user)
            ->post(route('tournaments.playoff', $tournament))
            ->assertSessionHas('status', 'Final generada.');

        $final = $tournament->games()->where('stage', 'final')->sole();
        $this->assertSame([$seeds[0], $seeds[2]], [$final->local_team_id, $final->visitor_team_id]);

        $final->update(['local_goals' => 0, 'visitor_goals' => 3, 'status' => 'finished']);

        $this->assertSame($seeds[2], (new LeaguePlayoff($tournament->fresh()))->champion()->id);
        $this->assertNull($league->nextPlayoffAction());

        $this->actingAs($user)
            ->get(route('tournaments.league', $tournament))
            ->assertOk()
            ->assertSee('Campeón');
    }

    public function test_ocho_clasificados_arman_cuartos_de_final(): void
    {
        $tournament = $this->createTournament(8, playoffTeams: 8);
        $league = new LeaguePlayoff($tournament);
        $league->generateSchedule();
        $tournament->games()->update(['status' => 'finished']);

        $this->assertSame('quarterfinal', $league->advancePlayoff());

        $seeds = $league->standings()->pluck('team.id')->all();
        $pairs = $tournament->games()->where('stage', 'quarterfinal')->orderBy('bracket_slot')->get()
            ->map(fn (Game $game) => [$game->local_team_id, $game->visitor_team_id])
            ->all();

        $this->assertSame([
            [$seeds[0], $seeds[7]],
            [$seeds[3], $seeds[4]],
            [$seeds[1], $seeds[6]],
            [$seeds[2], $seeds[5]],
        ], $pairs);
    }

    public function test_no_se_genera_la_liguilla_con_partidos_pendientes(): void
    {
        $tournament = $this->createTournament(4);
        $user = $this->createUser();
        (new LeaguePlayoff($tournament))->generateSchedule();

        $this->actingAs($user)
            ->from(route('tournaments.league', $tournament))
            ->post(route('tournaments.playoff', $tournament))
            ->assertSessionHasErrors(['playoff' => 'Primero deben terminar todos los partidos de la fase regular.']);

        $this->assertSame(0, $tournament->games()->where('stage', '!=', 'regular')->count());
    }

    private function assertEveryPairPlaysTimes($games, int $times): void
    {
        $pairs = $games->countBy(function (Game $game) {
            $ids = [$game->local_team_id, $game->visitor_team_id];
            sort($ids);

            return implode('-', $ids);
        });

        $teams = $games->flatMap(fn (Game $game) => [$game->local_team_id, $game->visitor_team_id])->unique()->count();

        $this->assertCount($teams * ($teams - 1) / 2, $pairs);
        $this->assertTrue($pairs->every(fn (int $count) => $count === $times));
    }

    private function playGame(Tournament $tournament, int $local, int $visitor, int $localGoals, int $visitorGoals, string $status = 'finished'): void
    {
        $tournament->games()->create([
            'local_team_id' => $local,
            'visitor_team_id' => $visitor,
            'field_id' => DB::table('fields')->value('id'),
            'date_time' => now(),
            'local_goals' => $localGoals,
            'visitor_goals' => $visitorGoals,
            'matchday' => 1,
            'stage' => 'regular',
            'status' => $status,
        ]);
    }

    private function createTournament(int $teams, int $rounds = 1, ?int $playoffTeams = 4): Tournament
    {
        $tournament = Tournament::create([
            'league_id' => $this->leagueId,
            'name' => 'Torneo de prueba',
            'format' => 'league_playoff',
            'rounds' => $rounds,
            'playoff_teams' => $playoffTeams,
            'start_date' => '2026-01-10',
            'status' => 'active',
        ]);

        $tournament->teams()->sync($this->createTeams($teams));

        return $tournament;
    }

    /**
     * @return array<int, int>
     */
    private function createTeams(int $count): array
    {
        return collect(range(1, $count))
            ->map(function () {
                $number = ++$this->teamNumber;

                return DB::table('teams')->insertGetId([
                    'locality_id' => $this->localityId,
                    'name' => "Equipo {$number}",
                    'slug' => "equipo-{$number}",
                ]);
            })
            ->all();
    }

    private function createUser(): User
    {
        $language = Language::firstOrCreate(
            ['iso_code' => 'es'],
            ['name' => 'Español', 'native_name' => 'Español']
        );

        return User::factory()->create(['language_id' => $language->id]);
    }
}
