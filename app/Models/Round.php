<?php

namespace App\Models;

use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['player_name', 'target_name', 'target_url', 'guess_ms', 'actual_ms', 'delta_ms'])]
class Round extends Model
{
    /** @use HasFactory<RoundFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guess_ms' => 'integer',
            'actual_ms' => 'integer',
            'delta_ms' => 'integer',
        ];
    }

    /**
     * Scope the query to the closest guesses first.
     */
    #[Scope]
    protected function closest(Builder $query): void
    {
        $query->orderBy('delta_ms')->orderBy('created_at');
    }

    /**
     * Scope the query to the most recent rounds first.
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->latest('id');
    }
}
