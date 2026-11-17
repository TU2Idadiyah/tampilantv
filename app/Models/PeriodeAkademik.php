<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodeAkademik extends Model
{
    protected $table = 'periode_akademik';

    protected $fillable = [
        'kode',
        'mulai',
        'selesai',
        'aktif',
    ];

    protected $casts = [
        'mulai' => 'date',
        'selesai' => 'date',
        'aktif' => 'boolean',
    ];

    public function jadwalKuliah(): HasMany
    {
        return $this->hasMany(JadwalKuliah::class, 'periode_id');
    }

    public function statistikMahasiswa(): HasMany
    {
        return $this->hasMany(StatistikMahasiswa::class, 'periode_id');
    }
}

