<?php

declare(strict_types=1);

namespace App\Enums;

enum Hari: int
{
    case SENIN = 1;
    case SELASA = 2;
    case RABU = 3;
    case KAMIS = 4;
    case JUMAT = 5;
    case SABTU = 6;
    case MINGGU = 7;

    public function label(): string
    {
        return match ($this) {
            self::SENIN => 'Senin',
            self::SELASA => 'Selasa',
            self::RABU => 'Rabu',
            self::KAMIS => 'Kamis',
            self::JUMAT => 'Jumat',
            self::SABTU => 'Sabtu',
            self::MINGGU => 'Minggu',
        };
    }

    public static function fromString(string $name): ?self
    {
        return match (strtolower(trim($name))) {
            'senin' => self::SENIN,
            'selasa' => self::SELASA,
            'rabu' => self::RABU,
            'kamis' => self::KAMIS,
            'jumat' => self::JUMAT,
            'sabtu' => self::SABTU,
            'minggu' => self::MINGGU,
            default => null,
        };
    }
}

