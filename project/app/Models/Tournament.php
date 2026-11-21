<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'league_id',
    'name',
    'season',
    'start_date',
    'end_date',
    'format',
    'rounds',
    'playoff_teams',
    'status',
])]
class Tournament extends Model
{
    use HasFactory;

    public const STATUSES = [
        'active' => 'Activo',
        'finished' => 'Finalizado',
        'cancelled' => 'Cancelado',
        'draft' => 'Borrador',
    ];

    public const FORMATS = [
        'league' => 'Liga (todos contra todos)',
        'league_playoff' => 'Liga con liguilla',
    ];

    public const ROUNDS = [
        1 => '1 vuelta',
        2 => '2 vueltas (ida y vuelta)',
    ];

    public const PLAYOFF_TEAMS = [
        2 => '2 equipos (final)',
        4 => '4 equipos (semifinales)',
        8 => '8 equipos (cuartos de final)',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'rounds' => 'integer',
            'playoff_teams' => 'integer',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'tournament_teams')->withTimestamps();
    }

    public function hasPlayoff(): bool
    {
        return $this->format === 'league_playoff';
    }

    public function isRoundRobin(): bool
    {
        return in_array($this->format, ['league', 'league_playoff'], true);
    }

    public function formatLabel(): string
    {
        return self::FORMATS[$this->format] ?? ($this->format ?: '—');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
