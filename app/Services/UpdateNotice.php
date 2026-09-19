<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Decides whether the "update available" bar should be on screen. The check
 * itself (a git fetch) is cached so page loads never wait on the network, and
 * the operator's answers live in a small file so they survive the cache being
 * cleared.
 */
class UpdateNotice
{
    private const CACHE_KEY = 'update-notice.check';

    private const CHECK_EVERY_MINUTES = 30;

    public const REMIND_AFTER_HOURS = 4;

    public function __construct(private UpdateService $updates) {}

    /** Look for a new version unless that was done recently. */
    public function refresh(bool $force = false): void
    {
        if (! $force && Cache::has(self::CACHE_KEY)) {
            return;
        }

        Cache::put(self::CACHE_KEY, $this->updates->checkForUpdates(), now()->addMinutes(self::CHECK_EVERY_MINUTES));
    }

    /**
     * The version to offer, or null when the bar should stay hidden.
     *
     * @return array{current: string, latest: string}|null
     */
    public function offer(): ?array
    {
        $check = Cache::get(self::CACHE_KEY);
        if (! is_array($check) || ! ($check['update_available'] ?? false)) {
            return null;
        }

        $answers = $this->answers();
        if (($answers['skipped_version'] ?? null) === $check['latest_version']) {
            return null;
        }

        if (($answers['remind_after'] ?? 0) > now()->getTimestamp()) {
            return null;
        }

        return ['current' => (string) $check['current_version'], 'latest' => (string) $check['latest_version']];
    }

    public function remindLater(): void
    {
        $this->saveAnswers(['remind_after' => now()->addHours(self::REMIND_AFTER_HOURS)->getTimestamp()] + $this->answers());
    }

    public function skip(string $version): void
    {
        $this->saveAnswers(['skipped_version' => $version] + $this->answers());
    }

    public function forgetCheck(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function answers(): array
    {
        $path = $this->path();

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function saveAnswers(array $answers): void
    {
        file_put_contents($this->path(), json_encode($answers, JSON_PRETTY_PRINT));
    }

    private function path(): string
    {
        return storage_path('app/update-notice.json');
    }
}
