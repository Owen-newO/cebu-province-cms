<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invitation extends Model
{
    protected $fillable = [
        'token',
        'municipal_slug',
        'created_by',
        'expires_at',
        'used_at',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'     => 'datetime',
            'used_at'        => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LguApplication::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function isExpired(): bool
    {
        return !$this->isUsed() && !$this->isDeactivated() && now()->greaterThan($this->expires_at);
    }

    public function isActive(): bool
    {
        return !$this->isUsed() && !$this->isDeactivated() && !$this->isExpired();
    }

    /**
     * One of: active | used | deactivated | expired.
     */
    public function getStatusAttribute(): string
    {
        if ($this->isUsed()) return 'used';
        if ($this->isDeactivated()) return 'deactivated';
        if ($this->isExpired()) return 'expired';

        return 'active';
    }
}
