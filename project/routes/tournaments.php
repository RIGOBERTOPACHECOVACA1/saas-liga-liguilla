<?php

use App\Http\Controllers\LeaguePlayoffController;
use App\Http\Controllers\TournamentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::delete('tournaments/{tournament?}', [TournamentController::class, 'destroy'])->name('tournaments.destroy');
    Route::resource('tournaments', TournamentController::class)->except(['show', 'destroy']);

    Route::get('tournaments/{tournament}/liga', [LeaguePlayoffController::class, 'show'])->name('tournaments.league');
    Route::post('tournaments/{tournament}/calendario', [LeaguePlayoffController::class, 'schedule'])->name('tournaments.schedule');
    Route::post('tournaments/{tournament}/liguilla', [LeaguePlayoffController::class, 'playoff'])->name('tournaments.playoff');
});
