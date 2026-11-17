<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dosen extends Model
{
    use SoftDeletes;

    protected $table = 'dosen';

    protected $fillable = [
        'kode_nidn',
        'nama',
        'foto_path',
        'no_hp',
        'pin',
        'aktif',
    ];

    protected $casts = [
        'aktif' => 'boolean',
    ];

    public function jadwalKuliah(): HasMany
    {
        return $this->hasMany(JadwalKuliah::class, 'dosen_id');
    }

    public function kehadiran(): HasMany
    {
        return $this->hasMany(KehadiranDosen::class, 'dosen_id');
    }
}

