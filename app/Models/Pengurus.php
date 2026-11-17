<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengurus extends Model
{
    use SoftDeletes;

    protected $table = 'pengurus';

    protected $fillable = [
        'nip',
        'nama',
        'jabatan',
        'urutan',
        'foto_path',
        'pin_hash',
        'aktif',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'aktif' => 'boolean',
    ];

    public function perangkat(): HasMany
    {
        return $this->hasMany(PerangkatPengurus::class, 'pengurus_id');
    }

    public function absensi(): HasMany
    {
        return $this->hasMany(AbsensiPengurus::class, 'pengurus_id');
    }

    public function absensiHariIni()
    {
        return $this->hasOne(AbsensiPengurus::class, 'pengurus_id')
            ->where('tanggal', date('Y-m-d'));
    }
}

