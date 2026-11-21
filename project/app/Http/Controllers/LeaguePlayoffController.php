<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Tournament;
use App\Support\LeaguePlayoff;

class LeaguePlayoffController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load(['league', 'teams']);
        $league = new LeaguePlayoff($tournament);

        $regularGames = $tournament->games()
            ->with(['localTeam', 'visitorTeam'])
            ->where('stage', 'regular')
            ->orderBy('matchday')
            ->orderBy('date_time')
            ->get()
            ->groupBy('matchday');

        $standings = $league->standings();

        return view('tournaments.league', [
            'tournament' => $tournament,
            'league' => $league,
            'standings' => $standings,
            'regularGames' => $regularGames,
            'bracket' => $league->bracket(),
            'champion' => $league->champion(),
            'nextAction' => $league->nextPlayoffAction(),
            'qualifiedIds' => $tournament->hasPlayoff()
                ? $standings->take((int) $tournament->playoff_teams)->pluck('team.id')->all()
                : [],
            'stages' => Game::STAGES,
        ]);
    }

    public function schedule(Tournament $tournament)
    {
        $created = (new LeaguePlayoff($tournament))->generateSchedule();

        return redirect()
            ->route('tournaments.league', $tournament)
            ->with('status', "Calendario generado: {$created} partidos.");
    }

    public function playoff(Tournament $tournament)
    {
        $stage = (new LeaguePlayoff($tournament))->advancePlayoff();

        return redirect()
            ->route('tournaments.league', $tournament)
            ->with('status', Game::STAGES[$stage].' generada.');
    }
}
