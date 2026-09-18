<?php

namespace App\Services\Feedback;

/**
 * Masks what must not leave an install before a report is stored or sent.
 *
 * Reports are written by people and by LLM sessions, and they quote logs.
 * "Please don't include secrets" is not a control; this is. It runs over every
 * free-text field and every collected diagnostic.
 */
class ReportRedactor
{
    public function redact(string $text): string
    {
        // Exact values this install knows to be sensitive, longest first so a
        // value that contains another is replaced whole.
        $known = array_filter($this->knownValues(), fn (string $value) => strlen($value) >= 4);
        uksort($known, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($known as $value => $label) {
            $text = str_replace($value, $label, $text);
        }

        $patterns = [
            // Private key blocks.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '<private key>',
            // Authorization headers and bearer tokens.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 <token>',
            // KEY=value / "key": "value" for secret-looking names.
            '/\b([A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|PASSWD|API_KEY|APP_KEY|PRIVATE_KEY)[A-Z0-9_]*)\s*[=:]\s*("?)[^\s"\',;]{4,}\2/i' => '$1=<redacted>',
            // user@host and bare IPv4 (loopback is harmless and useful).
            '/\b[A-Za-z0-9._-]+@(?:\d{1,3}\.){3}\d{1,3}\b/' => '<user>@<host>',
            '/\b(?!127\.0\.0\.1\b)(?:\d{1,3}\.){3}\d{1,3}\b/' => '<ip>',
            // Email addresses.
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/' => '<email>',
            // Key and certificate file paths.
            '#(?:/|~/)[^\s"\']*\.(?:pem|key|ppk|p12)\b#i' => '<key path>',
            '#(?:/|~/)[^\s"\']*/\.ssh/[^\s"\']+#' => '<key path>',
            // Long opaque strings (API tokens, hashes of secrets). No slashes,
            // so file paths survive. SHA-1 photo hashes are 40 hex characters
            // and are kept: they identify photos.
            '/\b(?![a-f0-9]{40}\b)[A-Za-z0-9+_-]{48,}={0,2}/' => '<long value>',
            // The account name in home-directory paths.
            '#/Users/[^/\s"\']+#' => '~',
            '#/home/(?!forge\b)[^/\s"\']+#' => '~',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * @return array<string, string> sensitive value => label
     */
    private function knownValues(): array
    {
        $values = [];

        foreach ([
            'proofgen.ferraraphoto.api_token' => '<api token>',
            'proofgen.feedback.token' => '<feedback token>',
            'proofgen.sftp.host' => '<sftp host>',
            'proofgen.sftp.private_key' => '<key path>',
            'app.key' => '<app key>',
        ] as $key => $label) {
            $value = config($key);
            if (is_string($value) && $value !== '') {
                $values[$value] = $label;
            }
        }

        return $values;
    }
}
