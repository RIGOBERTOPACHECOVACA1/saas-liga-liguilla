<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Vueltas de la fase regular: 1 = n(n-1)/2 partidos, 2 = n(n-1) partidos.
            $table->unsignedTinyInteger('rounds')->default(1)->after('format');
            // Equipos que clasifican a la liguilla (2, 4 u 8).
            $table->unsignedTinyInteger('playoff_teams')->nullable()->after('rounds');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->string('stage', 20)->default('regular')->after('matchday');
            // Posición de la llave dentro de la ronda de liguilla (0, 1, 2...).
            $table->unsignedTinyInteger('bracket_slot')->nullable()->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['stage', 'bracket_slot']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['rounds', 'playoff_teams']);
        });
    }
};
