<?php

namespace App\Services;

class PathResolver
{
    // Base paths
    public function getFullsizePath(string $show, string $class): string
    {
        return "/{$show}/{$class}";
    }

    // Originals path (within fullsize)
    public function getOriginalsPath(string $show, string $class): string
    {
        return "/{$show}/{$class}/originals";
    }

    // Proofs path (separate tree for rsync)
    public function getProofsPath(string $show, string $class = ''): string
    {
        if (empty($class)) {
            return "/proofs/{$show}";
        }

        return "/proofs/{$show}/{$class}";
    }

    // Web images path (separate tree for rsync)
    public function getWebImagesPath(string $show, string $class = ''): string
    {
        if (empty($class)) {
            return "/web_images/{$show}";
        }

        return "/web_images/{$show}/{$class}";
    }

    // Highres images path (separate tree for rsync)
    public function getHighresImagesPath(string $show, string $class = ''): string
    {
        if (empty($class)) {
            return "/highres_images/{$show}";
        }

        return "/highres_images/{$show}/{$class}";
    }

    // Archive path
    public function getArchivePath(string $show, string $class): string
    {
        return "/{$show}/{$class}";
    }

    // Remote paths
    public function getRemoteProofsPath(string $show, string $class = ''): string
    {
        if (empty($class)) {
            return "/{$show}";
        }

        return "/{$show}/{$class}";
    }

    public function getRemoteWebImagesPath(string $show, string $class = ''): string
    {
        if (empty($class)) {
            return "/{$show}";
        }

        return "/{$show}/{$class}";
    }

    public function getRemoteHighresImagesPath(string $show, string $class = ''): string
    {
        if (empty($class)) {
            return "/{$show}";
        }

        return "/{$show}/{$class}";
    }

    // Helper methods for specific file paths within directories

    // Original file path within originals directory
    public function getOriginalFilePath(string $show, string $class, string $filename): string
    {
        return $this->getOriginalsPath($show, $class)."/{$filename}";
    }

    // Get the path for a proof thumbnail with the given suffix
    public function getProofThumbnailPath(string $show, string $class, string $filename, string $suffix): string
    {
        // If the filename has an extension, remove it and add the suffix + extension
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        return $this->getProofsPath($show, $class)."/{$baseName}{$suffix}.jpg";
    }

    // Get the path for a web image with the configured suffix
    public function getWebImagePath(string $show, string $class, string $filename, string $suffix): string
    {
        // If the filename has an extension, remove it and add the suffix + extension
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        return $this->getWebImagesPath($show, $class)."/{$baseName}{$suffix}.jpg";
    }

    // Get the path for a highres image with the configured suffix
    public function getHighresImagePath(string $show, string $class, string $filename, string $suffix): string
    {
        // If the filename has an extension, remove it and add the suffix + extension
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        return $this->getHighresImagesPath($show, $class)."/{$baseName}{$suffix}.jpg";
    }

    // Helper method to ensure paths are properly formatted
    public function normalizePath(string $path): string
    {
        // Remove double slashes and ensure consistent formatting
        $normalized = str_replace('//', '/', $path);

        // For storage facades, we often want to remove the leading slash
        return ltrim($normalized, '/');
    }

    // Helper to add base path when needed for direct filesystem operations
    public function addBasePath(string $path, string $basePath): string
    {
        return rtrim($basePath, '/').'/'.ltrim($path, '/');
    }

    // Get the full absolute path for filesystem operations
    public function getAbsolutePath(string $path, string $basePath): string
    {
        return $this->addBasePath($this->normalizePath($path), $basePath);
    }

    // Show-specific paths (without class)

    // Get show-level proofs path
    public function getShowProofsPath(string $show): string
    {
        return $this->getProofsPath($show);
    }

    // Get show-level web images path
    public function getShowWebImagesPath(string $show): string
    {
        return $this->getWebImagesPath($show);
    }

    // Get show-level remote proofs path
    public function getShowRemoteProofsPath(string $show): string
    {
        return $this->getRemoteProofsPath($show);
    }

    // Get show-level remote web images path
    public function getShowRemoteWebImagesPath(string $show): string
    {
        return $this->getRemoteWebImagesPath($show);
    }

    // Get show-level highres images path
    public function getShowHighresImagesPath(string $show): string
    {
        return $this->getHighresImagesPath($show);
    }

    // Get show-level remote highres images path
    public function getShowRemoteHighresImagesPath(string $show): string
    {
        return $this->getRemoteHighresImagesPath($show);
    }
}
