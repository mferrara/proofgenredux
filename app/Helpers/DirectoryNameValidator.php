<?php

namespace App\Helpers;

class DirectoryNameValidator
{
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

        return true;
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

        return $validName;
    }
}
