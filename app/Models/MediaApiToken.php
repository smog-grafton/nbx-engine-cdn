<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MediaApiToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function issue(string $name, array $abilities = ['*'], ?Carbon $expiresAt = null): array
    {
        $plainTextToken = Str::random(64);

        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return [$token, $plainTextToken];
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }
}

