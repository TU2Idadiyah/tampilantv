<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    protected $fillable = [
        'kunci',
        'nilai',
        'tipe',
    ];

    protected $casts = [
        'nilai' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('kunci', $key)->first();
        return $setting ? $setting->nilai : $default;
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['kunci' => $key],
            ['nilai' => $value, 'tipe' => $type]
        );
    }
}

