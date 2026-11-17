<?php

declare(strict_types=1);

namespace App\Enums;

enum MetodeAbsen: string
{
    case QR = 'qr';
    case MANUAL = 'manual';
    case JADWAL = 'jadwal';

    public function label(): string
    {
        return match ($this) {
            self::QR => 'Pindai Kode QR',
            self::MANUAL => 'Koreksi Manual Admin',
            self::JADWAL => 'Otomatis Sesuai Jadwal',
        };
    }
}

