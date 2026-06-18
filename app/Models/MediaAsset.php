<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'type',
        'title',
        'description',
        'status',
        'visibility',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(MediaSource::class);
    }
}

