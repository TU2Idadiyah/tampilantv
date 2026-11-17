<?php

declare(strict_types=1);

namespace App\Services;

class WeatherService
{
    protected float $lat;
    protected float $lon;
    protected string $cachePath;

    public function __construct(float $lat = -7.6453, float $lon = 112.9075)
    {
        $this->lat = $lat;
        $this->lon = $lon;
        $this->cachePath = dirname(__DIR__, 2) . '/storage/weather.json';
    }

    public function getWeather(): array
    {
        if (file_exists($this->cachePath)) {
            $cached = json_decode(file_get_contents($this->cachePath), true);
            // Jika cache lebih muda dari 15 menit, gunakan langsung
            if ($cached && isset($cached['timestamp']) && (time() - $cached['timestamp'] < 900)) {
                return $cached['data'];
            }
        }

        return $this->fetchAndCache();
    }

    public function fetchAndCache(): array
    {
        $url = "https://api.open-meteo.com/v1/forecast?latitude={$this->lat}&longitude={$this->lon}&current=temperature_2m,weather_code&timezone=Asia%2FJakarta";

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response) {
            $json = json_decode($response, true);
            if (isset($json['current'])) {
                $weatherData = [
                    'suhu_c' => (int) round($json['current']['temperature_2m']),
                    'kode' => (int) $json['current']['weather_code'],
                    'usang' => false
                ];

                if (!is_dir(dirname($this->cachePath))) {
                    mkdir(dirname($this->cachePath), 0777, true);
                }

                file_put_contents($this->cachePath, json_encode([
                    'timestamp' => time(),
                    'data' => $weatherData
                ]));

                return $weatherData;
            }
        }

        // Fallback jika internet mati (PRD Bab 9: gunakan data terakhir dengan usang: true)
        if (file_exists($this->cachePath)) {
            $cached = json_decode(file_get_contents($this->cachePath), true);
            if ($cached && isset($cached['data'])) {
                $stale = $cached['data'];
                $stale['usang'] = true;
                return $stale;
            }
        }

        return [
            'suhu_c' => 31,
            'kode' => 2,
            'usang' => true
        ];
    }
}

