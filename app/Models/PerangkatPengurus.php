<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerangkatPengurus extends Model
{
    protected $table = 'perangkat_pengurus';

    protected $fillable = [
        'pengurus_id',
        'token_hash',
        'label',
        'terakhir_dipakai',
        'kedaluwarsa',
        'dicabut_pada',
    ];

    protected $casts = [
        'terakhir_dipakai' => 'datetime',
        'kedaluwarsa' => 'datetime',
        'dicabut_pada' => 'datetime',
    ];

    public function pengurus(): BelongsTo
    {
        return $this->belongsTo(Pengurus::class, 'pengurus_id');
    }
}

