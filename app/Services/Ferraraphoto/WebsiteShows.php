<?php

namespace App\Services\Ferraraphoto;

use Illuminate\Support\Facades\Cache;

/**
 * The shows that exist on the website, for picking one instead of typing a
 * slug. Shows are created on the website; Proofgen only chooses among them.
 *
 * Page renders only ever {@see peek()} at the cache. The API is called from an
 * explicit {@see load()} (a `wire:init` / refresh action), never while a
 * polling component re-renders.
 */
class WebsiteShows
{
    /** Gallery answered with its show list. */
    public const AVAILABLE = 'available';

    /** Older Gallery without the list, or no API token: slugs are typed by hand as before. */
    public const ABSENT = 'absent';

    /** Gallery could not be reached; nothing is known. */
    public const ERROR = 'error';

    private const TTL_SECONDS = 300;

    public function __construct(private FerraraphotoApiClient $api) {}

    /**
     * @return array{status: string, shows: array<int, array<string, mixed>>, message: ?string}|null
     */
    public function peek(): ?array
    {
        return Cache::get($this->cacheKey());
    }

    /**
     * @return array{status: string, shows: array<int, array<string, mixed>>, message: ?string}
     */
    public function load(bool $fresh = false): array
    {
        if (! $fresh && ($cached = $this->peek()) !== null) {
            return $cached;
        }

        $result = $this->fetch();

        // An error is not remembered: the next look should try again.
        if ($result['status'] === self::ERROR) {
            Cache::forget($this->cacheKey());
        } else {
            Cache::put($this->cacheKey(), $result, self::TTL_SECONDS);
        }

        return $result;
    }

    /**
     * Whether one slug exists on the website, from the cache only. Null when
     * that is not known: no show list on this Gallery, Gallery unreachable, or
     * {@see check()} has not looked yet.
     */
    public function exists(string $slug): ?bool
    {
        $list = $this->peek();

        if (($list['status'] ?? null) !== self::AVAILABLE) {
            return null;
        }

        foreach ($list['shows'] as $show) {
            if (($show['slug'] ?? null) === $slug) {
                return true;
            }
        }

        return match (Cache::get($this->cacheKey().':'.$slug)) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * Ask Gallery about one slug. The list holds only the newest shows, so an
     * older show is looked up by name. Pass $fresh after creating the show on
     * the website (the panel's Re-check button does).
     */
    public function check(string $slug, bool $fresh = false): ?bool
    {
        $this->load($fresh);

        if ($fresh) {
            Cache::forget($this->cacheKey().':'.$slug);
        }

        if (($known = $this->exists($slug)) !== null || ($this->peek()['status'] ?? null) !== self::AVAILABLE) {
            return $known;
        }

        try {
            $exists = $this->api->readShow($slug) !== null;
        } catch (FerraraphotoApiException) {
            return null;
        }

        Cache::put($this->cacheKey().':'.$slug, $exists ? 'yes' : 'no', self::TTL_SECONDS);

        return $exists;
    }

    public function newShowUrl(): string
    {
        return $this->origin().'/admin/shows/create';
    }

    /**
     * @return array{status: string, shows: array<int, array<string, mixed>>, message: ?string}
     */
    private function fetch(): array
    {
        if (blank(config('proofgen.ferraraphoto.api_token'))) {
            return ['status' => self::ABSENT, 'shows' => [], 'message' => null];
        }

        try {
            $shows = $this->api->listShows();
        } catch (FerraraphotoApiException $exception) {
            return ['status' => self::ERROR, 'shows' => [], 'message' => $exception->getMessage()];
        }

        if ($shows === null) {
            return ['status' => self::ABSENT, 'shows' => [], 'message' => null];
        }

        return [
            'status' => self::AVAILABLE,
            'shows' => array_values(array_filter($shows, fn ($show) => is_array($show) && filled($show['slug'] ?? null))),
            'message' => null,
        ];
    }

    private function cacheKey(): string
    {
        return 'website-shows:'.sha1($this->origin());
    }

    private function origin(): string
    {
        return rtrim((string) config('proofgen.ferraraphoto.base_url'), '/');
    }
}
