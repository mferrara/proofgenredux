<?php

namespace App\Services\Transport;

/**
 * Immutable outcome of a single rsync invocation.
 *
 * stdout is expected to carry rsync's `-ii --out-format=%i %n` itemize lines
 * when the command was built by RsyncCommandBuilder; the parsers below read
 * that deterministic format instead of guessing from human-readable output.
 *
 * Two distinct questions are answered from the same output:
 *
 *  - {@see syncedFiles()} is the successful-sync manifest: every regular file
 *    rsync itself enumerated and confirmed as either transferred or already
 *    up to date (`.f` entries, emitted because `-i` is repeated). Because the
 *    sender builds this list itself, a local file that appears or disappears
 *    around the run is only ever covered when rsync actually saw it.
 *  - {@see transferredFiles()} is only the entries rsync actually sent or
 *    received (content transfers), so per-run "this was re-uploaded" checks
 *    never treat attribute-only changes as a new upload.
 */
final class RsyncRunResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Regular files rsync sent or received (content transfers). Under
     * --dry-run these are the files rsync would transfer; on a real run they
     * were actually written at the destination.
     *
     * @return array<int, string> paths relative to the transfer root
     */
    public function transferredFiles(): array
    {
        return $this->parseItemizedFiles(
            fn (string $itemize): bool => $itemize[0] === '<' || $itemize[0] === '>'
        );
    }

    /**
     * Regular files rsync confirmed at the destination: transferred files plus
     * unchanged files (`-ii` `.f` entries) and hard-link/creation entries.
     *
     * The first 11 characters of an itemize line are the YXcstpoguax marker:
     * character 0 is the update type and character 1 the file type. Directory
     * lines (`cd+++++++++ sub/`), deletions and non-regular entries are never
     * treated as file evidence.
     *
     * @return array<int, string> paths relative to the transfer root
     */
    public function syncedFiles(): array
    {
        return $this->parseItemizedFiles(
            fn (string $itemize): bool => in_array($itemize[0], ['<', '>', 'c', 'h', '.'], true)
        );
    }

    /**
     * @param  callable(string): bool  $accepts  receives the 11-char itemize marker
     * @return array<int, string>
     */
    private function parseItemizedFiles(callable $accepts): array
    {
        $files = [];

        foreach (preg_split('/\r\n|\n|\r/', $this->stdout) ?: [] as $line) {
            // Marker (11) + separator space (1) + at least one path character.
            if (strlen($line) < 13 || $line[11] !== ' ') {
                continue;
            }

            $itemize = substr($line, 0, 11);

            if ($itemize[1] !== 'f' || ! $accepts($itemize)) {
                continue;
            }

            $name = substr($line, 12);

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $files[] = $name;
        }

        return array_values(array_unique($files));
    }
}
