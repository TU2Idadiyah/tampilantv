<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengumuman extends Model
{
    protected $table = 'pengumuman';

    protected $fillable = [
        'teks',
        'urutan',
        'tampil_dari',
        'tampil_sampai',
        'aktif',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'tampil_dari' => 'date',
        'tampil_sampai' => 'date',
        'aktif' => 'boolean',
    ];
}

