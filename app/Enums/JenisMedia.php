<?php

declare(strict_types=1);

namespace App\Enums;

enum JenisMedia: string
{
    case VIDEO_PROFIL = 'video_profil';
    case LOGO = 'logo';

    public function label(): string
    {
        return match ($this) {
            self::VIDEO_PROFIL => 'Video Profil Kampus',
            self::LOGO => 'Logo Resmi Kampus',
        };
    }
}

