# Image enhancement

Use [Core Image enhancement on macOS](core-image-enhancement.md) as the maintained
reference for methods, parameters, fallback behavior, encoding, and renderer setup.

In Settings → Enhancement, enable enhancement, choose adjustable auto-levels or
advanced tone mapping, and choose whether it applies to proofs, web images and/or
high-resolution images. Preview changes in Settings → Images before saving.
Preview-only width, height and quality values do not persist until saved.

The older ImageMagick/CLAHE/Smart Indoor/S-curve description in this document did
not match the current implementation and has been retired. The current renderer
uses the Core Image daemon with a GD fallback; unsupported fallback adjustments
are reported rather than silently approximated.

All four previews support the tested 45 MP EXIF portrait original. Long proof
labels fit the available width; small/large watermarked proofs finish at JPEG
quality 95. See [the latest validation](reviews/2026-09-13-show-prep-finish.md).
Single-pass encoding and format selection remain separate research work.
