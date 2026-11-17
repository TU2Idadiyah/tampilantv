<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agenda extends Model
{
    protected $table = 'agenda';

    protected $fillable = [
        'judul',
        'lokasi',
        'mulai_at',
        'selesai_at',
        'tampil_dari',
        'tampil_sampai',
        'aktif',
    ];

    protected $casts = [
        'mulai_at' => 'datetime',
        'selesai_at' => 'datetime',
        'tampil_dari' => 'date',
        'tampil_sampai' => 'date',
        'aktif' => 'boolean',
    ];
}

