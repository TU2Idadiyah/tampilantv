<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KehadiranDosen extends Model
{
    protected $table = 'kehadiran_dosen';

    protected $fillable = [
        'dosen_id',
        'jadwal_id',
        'tanggal',
        'status',
        'mode',
        'jam_ganti',
        'keterangan',
        'waktu_absen',
        'metode',
        'ip_address',
    ];

    protected $casts = [
        'tanggal' => 'date',
    ];

    public function dosen(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_id');
    }
}

