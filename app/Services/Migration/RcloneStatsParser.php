<?php

namespace App\Services\Migration;

class RcloneStatsParser
{
    /**
     * @return array{ok: bool, errors: int, transferred_files: ?int}
     */
    public function parse(string $output, int $exitCode): array
    {
        $errors = $this->firstIntegerAfter($output, 'Errors:') ?? 0;

        return [
            'ok' => $exitCode === 0 && $errors === 0,
            'errors' => $errors,
            'transferred_files' => $this->transferredFiles($output),
        ];
    }

    private function firstIntegerAfter(string $output, string $label): ?int
    {
        if (! preg_match('/'.preg_quote($label, '/').'\s+([0-9,]+)/i', $output, $matches)) {
            return null;
        }

        return (int) str_replace(',', '', $matches[1]);
    }

    private function transferredFiles(string $output): ?int
    {
        if (! preg_match('/Transferred:\s+([0-9,]+)\s*\/\s*([0-9,]+)/i', $output, $matches)) {
            return null;
        }

        return (int) str_replace(',', '', $matches[1]);
    }
}
