<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JenisMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'koleksi',
        'path',
        'mime',
        'ukuran_byte',
        'durasi_detik',
        'aktif',
        'diunggah_oleh',
    ];

    protected $casts = [
        'koleksi' => JenisMedia::class,
        'ukuran_byte' => 'integer',
        'durasi_detik' => 'integer',
        'aktif' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }
}

