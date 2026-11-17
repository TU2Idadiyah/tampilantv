<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Hari;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JadwalKuliah extends Model
{
    protected $table = 'jadwal_kuliah';

    protected $fillable = [
        'periode_id',
        'prodi_id',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'mata_kuliah',
        'kelas',
        'dosen_id',
        'ruang',
        'aktif',
    ];

    protected $casts = [
        'hari' => Hari::class,
        'aktif' => 'boolean',
    ];

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeAkademik::class, 'periode_id');
    }

    public function prodi(): BelongsTo
    {
        return $this->belongsTo(Prodi::class, 'prodi_id');
    }

    public function dosen(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_id');
    }
}

