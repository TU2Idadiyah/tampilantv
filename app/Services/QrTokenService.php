<?php

declare(strict_types=1);

namespace App\Services;

class QrTokenService
{
    protected string $secret;
    protected string $baseUrl;
    protected int $windowDuration;
    protected int $tolerance;

    public function __construct(?string $secret = null, ?string $baseUrl = null, int $windowDuration = 30, int $tolerance = 1)
    {
        $this->secret = $secret ?? (getenv('QR_SECRET') ?: 'stai_sidogiri_secret_key_2026');
        $this->baseUrl = $baseUrl ?? 'http://localhost/tampilantv/absen/scan';
        $this->windowDuration = $windowDuration;
        $this->tolerance = $tolerance;
    }

    public function getCurrentWindow(): int
    {
        return (int) floor(time() / $this->windowDuration);
    }

    public function getRemainingWindowSeconds(): int
    {
        return $this->windowDuration - (time() % $this->windowDuration);
    }

    public function generateCode(int $pengurusId, ?int $window = null): string
    {
        $window = $window ?? $this->getCurrentWindow();
        $signature = hash_hmac('sha256', "{$pengurusId}|{$window}", $this->secret);

        return "{$pengurusId}.{$window}.{$signature}";
    }

    public function generateSignedUrl(int $pengurusId, ?int $window = null): string
    {
        $code = $this->generateCode($pengurusId, $window);
        return "{$this->baseUrl}?c={$code}";
    }

    /**
     * Memvalidasi kode QR sesuai PRD Bab 8:
     * - Memeriksa format <id>.<window>.<signature>
     * - Memeriksa kecocokan ID pengurus
     * - Memeriksa batas jendela waktu (toleransi jendela saat ini dan $tolerance jendela sebelumnya)
     * - Memeriksa tanda tangan HMAC menggunakan hash_equals
     */
    public function validateCode(string $code, int $expectedPengurusId): array
    {
        $parts = explode('.', trim($code));
        if (count($parts) !== 3) {
            return ['valid' => false, 'error' => 'FORMAT_TIDAK_VALID'];
        }

        [$idStr, $windowStr, $signature] = $parts;
        $id = (int) $idStr;
        $window = (int) $windowStr;

        // Anti-titip: ID pemilik QR harus sesuai pengurus login
        if ($id !== $expectedPengurusId) {
            return ['valid' => false, 'error' => 'PERANGKAT_TIDAK_COCOK'];
        }

        // Cek Jendela Waktu
        $currentWindow = $this->getCurrentWindow();
        $allowedWindows = [];
        for ($i = 0; $i <= $this->tolerance; $i++) {
            $allowedWindows[] = $currentWindow - $i;
        }

        if (!in_array($window, $allowedWindows, true)) {
            return ['valid' => false, 'error' => 'QR_KEDALUWARSA'];
        }

        // Cek Tanda Tangan HMAC
        $expectedSignature = hash_hmac('sha256', "{$id}|{$window}", $this->secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return ['valid' => false, 'error' => 'TANDA_TANGAN_PALSU'];
        }

        return ['valid' => true, 'pengurus_id' => $id, 'window' => $window];
    }
}

