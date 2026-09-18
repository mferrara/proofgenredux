<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class DirectoryNameValidator
{
    /**
     * What the website accepts as a class number, minus spaces (which Proofgen
     * never allowed): letters, digits, dot, underscore and hyphen; starting and
     * ending with a letter or digit; no ".."; 32 characters at most.
     */
    public const WEBSITE_PATTERN = '/^(?!.*\.\.)[A-Za-z0-9](?:[A-Za-z0-9._-]{0,30}[A-Za-z0-9])?\z/';

    public const MAX_LENGTH = 32;

    /**
     * Check if a directory name is valid for use as a class folder
     */
    public static function isValid(string $directoryName): bool
    {
        // Check if the directory name contains spaces
        if (str_contains($directoryName, ' ')) {
            return false;
        }

        // Check for other potentially problematic characters
        // These characters can cause issues across different operating systems
        $invalidCharacters = ['<', '>', ':', '"', '|', '?', '*', "\0"];
        foreach ($invalidCharacters as $char) {
            if (str_contains($directoryName, $char)) {
                return false;
            }
        }

        // Check if the name starts or ends with a dot (hidden files/folders)
        if (str_starts_with($directoryName, '.') || str_ends_with($directoryName, '.')) {
            return false;
        }

        // Check for Windows reserved names
        $reservedNames = [
            'CON', 'PRN', 'AUX', 'NUL',
            'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
            'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
        ];
        if (in_array(strtoupper($directoryName), $reservedNames)) {
            return false;
        }

        // The website stores the folder name as the class number and rejects
        // anything else. A folder it would reject could be imported and proofed
        // here but never uploaded - and used to block the whole show's uploads.
        return preg_match(self::WEBSITE_PATTERN, $directoryName) === 1;
    }

    /**
     * Get the validation error message for an invalid directory name
     */
    public static function getValidationError(string $directoryName): ?string
    {
        if (str_contains($directoryName, ' ')) {
            return 'Directory name contains spaces. Use hyphens or underscores instead.';
        }

        $invalidCharacters = ['<', '>', ':', '"', '|', '?', '*', "\0"];
        foreach ($invalidCharacters as $char) {
            if (str_contains($directoryName, $char)) {
                return "Directory name contains invalid character: '{$char}'";
            }
        }

        if (str_starts_with($directoryName, '.') || str_ends_with($directoryName, '.')) {
            return 'Directory name cannot start or end with a dot.';
        }

        $reservedNames = [
            'CON', 'PRN', 'AUX', 'NUL',
            'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
            'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
        ];
        if (in_array(strtoupper($directoryName), $reservedNames)) {
            return 'Directory name is a reserved system name.';
        }

        if (strlen($directoryName) > self::MAX_LENGTH) {
            return 'Directory name is longer than '.self::MAX_LENGTH.' characters; the website will not accept it.';
        }

        if (preg_match('/[^A-Za-z0-9._-]/', $directoryName, $match) === 1) {
            return "Directory name contains '{$match[0]}'. Use only letters, numbers, dots, hyphens and underscores; the website will not accept anything else.";
        }

        if (str_contains($directoryName, '..')) {
            return 'Directory name cannot contain two dots in a row.';
        }

        if (preg_match(self::WEBSITE_PATTERN, $directoryName) !== 1) {
            return 'Directory name must start and end with a letter or number; the website will not accept it otherwise.';
        }

        return null;
    }

    /**
     * Suggest a valid directory name based on an invalid one
     */
    public static function suggestValidName(string $invalidName): string
    {
        // Replace spaces with underscores
        $validName = str_replace(' ', '_', $invalidName);

        // Remove invalid characters
        $invalidCharacters = ['<', '>', ':', '"', '|', '?', '*', "\0"];
        $validName = str_replace($invalidCharacters, '', $validName);

        // Remove leading/trailing dots
        $validName = trim($validName, '.');

        // If the name is now empty or is a reserved name, append a suffix
        if (empty($validName) || in_array(strtoupper($validName), [
            'CON', 'PRN', 'AUX', 'NUL',
            'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
            'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
        ])) {
            $validName = $validName.'_class';
        }

        // Make it something the website accepts too: plain ASCII, separators
        // only in the middle, no repeats, 32 characters at most.
        $validName = Str::ascii(str_replace('&', ' and ', $validName));
        $validName = (string) preg_replace('/\s+/', '_', trim($validName));
        $validName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $validName);
        $validName = (string) preg_replace('/([._-])[._-]+/', '$1', $validName);
        $validName = trim(substr(trim($validName, '._-'), 0, self::MAX_LENGTH), '._-');

        if ($validName === '' || ! self::isValid($validName)) {
            $validName = 'class';
        }

        return $validName;
    }
}
