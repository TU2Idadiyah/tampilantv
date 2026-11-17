<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
    protected $table = 'import_log';

    protected $fillable = [
        'jenis',
        'nama_file',
        'hash_file',
        'total_baris',
        'berhasil',
        'gagal',
        'laporan_path',
        'status',
        'user_id',
    ];

    protected $casts = [
        'total_baris' => 'integer',
        'berhasil' => 'integer',
        'gagal' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

