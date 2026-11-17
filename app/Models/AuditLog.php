<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'aktor_tipe',
        'aktor_id',
        'aksi',
        'entitas',
        'entitas_id',
        'meta',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public static function log(string $aktorTipe, ?int $aktorId, string $aksi, string $entitas, ?int $entitasId = null, ?array $meta = null, ?string $ip = null): static
    {
        return static::create([
            'aktor_tipe' => $aktorTipe,
            'aktor_id' => $aktorId,
            'aksi' => $aksi,
            'entitas' => $entitas,
            'entitas_id' => $entitasId,
            'meta' => $meta,
            'ip_address' => $ip ?? request()->ip(),
            'created_at' => now(),
        ]);
    }
}

