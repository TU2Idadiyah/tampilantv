<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodeAbsen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsensiPengurus extends Model
{
    protected $table = 'absensi_pengurus';

    protected $fillable = [
        'pengurus_id',
        'tanggal',
        'sesi_ke',
        'waktu_masuk',
        'waktu_absen',
        'waktu_pulang',
        'status',
        'durasi_menit',
        'metode',
        'metode_masuk',
        'metode_pulang',
        'perangkat_id',
        'perangkat_id_pulang',
        'ip_address',
        'ip_pulang',
        'dibuat_oleh',
        'catatan',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'metode' => MetodeAbsen::class,
    ];

    public function pengurus(): BelongsTo
    {
        return $this->belongsTo(Pengurus::class, 'pengurus_id');
    }

    public function perangkat(): BelongsTo
    {
        return $this->belongsTo(PerangkatPengurus::class, 'perangkat_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}

