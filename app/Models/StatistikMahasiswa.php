<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatistikMahasiswa extends Model
{
    protected $table = 'statistik_mahasiswa';

    protected $fillable = [
        'periode_id',
        'prodi_id',
        'tahun_angkatan',
        'jumlah',
    ];

    protected $casts = [
        'tahun_angkatan' => 'integer',
        'jumlah' => 'integer',
    ];

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeAkademik::class, 'periode_id');
    }

    public function prodi(): BelongsTo
    {
        return $this->belongsTo(Prodi::class, 'prodi_id');
    }
}

