<?php

namespace App\Models;

use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['player_name', 'target_name', 'target_url', 'guess_ms', 'actual_ms', 'cold_ms', 'latency_ms', 'delta_ms'])]
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
            'cold_ms' => 'integer',
            'latency_ms' => 'integer',
            'delta_ms' => 'integer',
        ];
    }

    /**
     * Get the verdict for how close the guess landed.
     *
     * @return Attribute<string, never>
     */
    protected function verdict(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->delta_ms <= 50 => '🎯 Unbelievable!',
            $this->delta_ms <= 250 => '🔥 So close!',
            $this->delta_ms <= 1000 => '👏 Not bad!',
            default => '🐢 Better luck next time!',
        });
    }

    /**
     * Scope the query to the closest guesses first, ties going to the most recent win.
     */
    #[Scope]
    protected function closest(Builder $query): void
    {
        $query->orderBy('delta_ms')->orderByDesc('created_at')->orderByDesc('id');
    }
}
