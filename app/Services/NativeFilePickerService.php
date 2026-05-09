<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Shells out to macOS `osascript` to invoke the native Finder file/folder
 * chooser. The dialog appears on the Mac the operator is sitting at; the
 * chosen POSIX path comes back as a string. Cancelling returns null.
 *
 * Single-tenant local desktop app — see CLAUDE.md trust model. There is no
 * remote-server case to defend against. We do still validate that osascript
 * exists and that the returned path is non-empty + an absolute path before
 * handing it back.
 */
class NativeFilePickerService
{
    private const OSASCRIPT = '/usr/bin/osascript';

    /**
     * Show a "Choose Folder" dialog. Returns the absolute POSIX path of the
     * selected directory, or null if the operator cancelled.
     *
     * @param  string|null  $initialPath  Optional path the dialog opens to.
     * @param  string|null  $prompt  Optional dialog title.
     */
    public function pickFolder(?string $initialPath = null, ?string $prompt = null): ?string
    {
        $script = "tell application \"System Events\" to activate\n";
        $script .= 'set folderRef to choose folder';
        if ($prompt !== null && $prompt !== '') {
            $script .= ' with prompt '.$this->quoteString($prompt);
        }
        if ($initialPath !== null && $initialPath !== '' && is_dir($initialPath)) {
            $script .= ' default location (POSIX file '.$this->quoteString($initialPath).')';
        }
        $script .= "\n".'return POSIX path of folderRef';

        return $this->runOsascript($script);
    }

    /**
     * Show a "Choose File" dialog. Returns the absolute POSIX path of the
     * selected file, or null if the operator cancelled.
     *
     * @param  string|null  $initialPath  Directory the dialog opens to.
     * @param  string|null  $prompt  Optional dialog title.
     * @param  array<string>|null  $ofType  Optional UTI list (e.g. ['public.executable']) or extensions ['pem', 'key'].
     */
    public function pickFile(?string $initialPath = null, ?string $prompt = null, ?array $ofType = null): ?string
    {
        $script = "tell application \"System Events\" to activate\n";
        $script .= 'set fileRef to choose file';
        if ($prompt !== null && $prompt !== '') {
            $script .= ' with prompt '.$this->quoteString($prompt);
        }
        if ($ofType !== null && $ofType !== []) {
            $list = implode(', ', array_map(fn ($t) => $this->quoteString($t), $ofType));
            $script .= ' of type {'.$list.'}';
        }
        if ($initialPath !== null && $initialPath !== '' && is_dir($initialPath)) {
            $script .= ' default location (POSIX file '.$this->quoteString($initialPath).')';
        }
        $script .= "\n".'return POSIX path of fileRef';

        return $this->runOsascript($script);
    }

    private function runOsascript(string $script): ?string
    {
        if (! is_executable(self::OSASCRIPT)) {
            throw new RuntimeException('osascript not found at '.self::OSASCRIPT.' — native picker requires macOS.');
        }

        $result = Process::run([self::OSASCRIPT, '-e', $script]);

        // Cancel returns exit 1 with stderr "User canceled. (-128)" — that's not an error condition.
        if ($result->failed()) {
            if (str_contains($result->errorOutput(), '-128') || str_contains($result->errorOutput(), 'canceled')) {
                return null;
            }
            throw new RuntimeException('osascript failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $path = trim($result->output());
        if ($path === '') {
            return null;
        }
        // Strip osascript's trailing slash on directories so paths compare cleanly.
        $path = rtrim($path, "/\n\r");
        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('osascript returned a non-absolute path: '.$path);
        }

        return $path;
    }

    private function quoteString(string $value): string
    {
        // AppleScript string escapes: " and \ → \" and \\
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
