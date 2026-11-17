<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

class CsvReader
{
    /**
     * Membaca berkas CSV dengan deteksi otomatis pemisah (;, ,), UTF-8 BOM, dan normalisasi header
     */
    public function readFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("Berkas CSV tidak ditemukan: {$filePath}");
        }

        $rawContent = file_get_contents($filePath);
        if ($rawContent === false || strlen($rawContent) === 0) {
            throw new Exception("Berkas CSV kosong.");
        }

        // Hapus UTF-8 BOM jika ada
        if (str_starts_with($rawContent, "\xEF\xBB\xBF")) {
            $rawContent = substr($rawContent, 3);
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($rawContent));
        if (empty($lines)) {
            throw new Exception("Berkas CSV tidak memiliki baris data.");
        }

        // Deteksi Delimiter (titik koma ';' atau koma ',')
        $firstLine = $lines[0];
        $semicolonCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');
        $delimiter = $semicolonCount >= $commaCount ? ';' : ',';

        // Baca Header dan Normalisasi (lowercase, trim)
        $rawHeaders = str_getcsv(array_shift($lines), $delimiter);
        $headers = array_map(fn($h) => strtolower(trim((string)$h)), $rawHeaders);

        $rows = [];
        $lineNumber = 1;

        foreach ($lines as $line) {
            $lineNumber++;
            $line = trim($line);
            if ($line === '') continue;

            $cols = str_getcsv($line, $delimiter);
            $rowAssoc = [];

            foreach ($headers as $idx => $headerName) {
                $rowAssoc[$headerName] = isset($cols[$idx]) ? trim((string)$cols[$idx]) : '';
            }

            $rows[] = [
                'line_number' => $lineNumber,
                'data' => $rowAssoc
            ];

            if (count($rows) > 5000) {
                throw new Exception("Jumlah baris melebihi batas maksimal 5.000 baris.");
            }
        }

        return [
            'delimiter' => $delimiter,
            'headers' => $headers,
            'total_baris' => count($rows),
            'rows' => $rows
        ];
    }
}

