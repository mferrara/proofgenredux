# Proofgen Redux

A Laravel application for processing event photography images, managing watermarks, and uploading to a photography sales platform.

## Overview

Proofgen Redux monitors directories containing full-size images from photographers and processes them through a configurable workflow:

1. **Image Processing Steps**:
   - Renames files with proof numbers (configurable)
   - Creates verified archive copies on the configured archive disk (configurable)
   - Generates thumbnails with watermarks (configurable)
   - Creates web-optimized versions (configurable)
   - Uploads proofs & web images to remote server via SFTP/rsync (configurable)

2. **Key Features**:
   - Web-based interface using Laravel Livewire and FluxUI components
   - Background job processing for large batches
   - Configurable watermarks and image settings
   - Database-driven configuration (moving away from .env files)
   - Support for shows (events) and classes (subdivisions within shows)

## Requirements

### Development Environment (2024)
- Laravel Herd
- Redis (via Herd)
- Raised PHP memory limit in Herd
- `brew install rsync` for modern version of rsync

### PHP Extensions
- fileinfo
- exif
- gd
- pcntl

## macOS Setup for Core Image Enhancement

Proofgen Redux includes optional GPU-accelerated image enhancement using macOS Core Image framework. This provides significant performance improvements for image processing operations.

### Requirements for Core Image Enhancement

1. **macOS** (required - Core Image is macOS-specific)
2. **Swift 5.5 or higher** (for the enhancement daemon)
3. **Xcode Command Line Tools** or full Xcode installation

### Setting up Swift Environment

#### Option 1: Install Xcode Command Line Tools (Recommended)
```bash
# Install Command Line Tools (smaller download, ~2GB)
xcode-select --install

# Verify Swift installation
swift --version
```

#### Option 2: Install Full Xcode
1. Download Xcode from the Mac App Store
2. Launch Xcode once to complete installation
3. Accept license agreements when prompted

### Verifying Core Image Compatibility

```bash
# Check Swift compatibility
php artisan proofgen:swift-check

# Check Core Image daemon status
php artisan proofgen:coreimage-daemon status

# Start Core Image daemon
php artisan proofgen:coreimage-daemon start
```

### Troubleshooting

If Core Image enhancement is unavailable:
1. The system will automatically fall back to standard GD image processing
2. Check the Settings page in the web UI for compatibility warnings
3. Review logs at `storage/logs/laravel.log` for detailed error messages

### Performance Notes

- Core Image provides GPU acceleration on Apple Silicon Macs (M1/M2/M3)
- Intel Macs will use Core Image but with CPU-based processing
- Enhancement processing is typically 2-5x faster with Core Image enabled

## Installation

```bash
# Install dependencies
composer install
npm install

# Set up database
php artisan migrate

# Build frontend assets
npm run build
```

## Development Commands

```bash
# Development server
php artisan serve
npm run dev

# Build for production
npm run build

# Run all tests
./vendor/bin/pest

# Run a single test
./vendor/bin/pest tests/path/to/test.php

# Code style checking
./vendor/bin/pint

# Laravel artisan commands
php artisan migrate           # Run database migrations
php artisan make:model Name   # Create a new model
```

## Directory Structure

The application expects a specific directory structure for processing images:

```
FULLSIZE_HOME_DIR/
├── ShowName/              # e.g., "2023R41"
│   ├── ClassID/          # e.g., "121", "127"
│   │   ├── IMG_xxxx.jpg  # Raw images to process
│   │   └── originals/    # Renamed images moved here after processing
├── proofs/
│   └── ShowName/
│       └── ClassID/      # Generated thumbnails/proofs
├── web_images/
│   └── ShowName/
│       └── ClassID/      # Generated web-resolution images
└── highres_images/
    └── ShowName/
        └── ClassID/      # Generated high-resolution images
```

### Configuration
- **FULLSIZE_HOME_DIR / fullsize_home_dir**: Base directory containing show folders. The database-backed setting is applied to the `fullsize` filesystem disk at runtime.
- **ARCHIVE_HOME_DIR / archive_home_dir**: Backup location for full-size images, ideally a fast external drive carried separately from the laptop. The database-backed setting is applied to the `archive` filesystem disk at runtime.

## Photo Archive Backups

When archive backups are enabled, import writes a second verified copy of each imported original to the `archive` disk before the ingest source file is removed from the class folder. Archive files use the current recoverable class layout:

```
ARCHIVE_HOME_DIR/
├── ShowName/
│   └── ClassID/
│       └── ProofNumber.jpg
```

Each `photos` record stores `archive_path`, `archive_sha1`, `archive_size`, and `archived_at` so the database can report whether the local original has a matching backup copy. If an archive file already exists with the same contents, import reuses it. If a different file already exists at that path, Proofgen moves the old copy into that class's `_conflicts` folder before writing the new current copy; it does not blind-delete the old backup.

Class renames and selected photo moves also move the archive copy and refresh the photo archive metadata. Resetting a class moves archive files to the reset filenames so the archive stays close to the current local filesystem state. Deleting photos from the UI does not delete archive copies.

Use the audit command to check or repair archive coverage:

```bash
# Report archive state for all photos
php artisan proofgen:audit-archives

# Limit to one show or class
php artisan proofgen:audit-archives --show=2023R41
php artisan proofgen:audit-archives --show=2023R41 --class=121

# Backfill missing/mismatched archive copies from local originals and refresh metadata
php artisan proofgen:audit-archives --repair

# Machine-readable output
php artisan proofgen:audit-archives --format=json
```

## Main Components

### Livewire Components
- **HomeComponent**: Directory navigation
- **ShowViewComponent**: Show-level operations
- **ClassViewComponent**: Class-level operations
- **ConfigComponent**: Configuration management

### Core Classes
- **Show**: Represents a photography event
- **ShowClass**: Represents a class within a show
- **Image**: Handles image processing

### Background Jobs
- **ImportPhoto**: Process single image
- **GenerateThumbnails**: Create thumbnails
- **GenerateWebImage**: Create web versions
- **UploadProofs**: Upload to remote server
- **ImportPhotos**: Batch process images

## Sample Images for Testing

The application uses a sample image system that can automatically download test images from an S3-compatible bucket (like Digital Ocean Spaces):

### Setup

1. **Setup S3 bucket**:
   - Create a bucket for sample images (separate from production images)
   - Add appropriate sample files in the structure `{show}/{class}/{image.jpg}`
   - Standard sample data includes shows like "2023R41" with classes "121" and "127"

2. **Environment configuration**:
   ```
   SAMPLE_IMAGES_S3_KEY=your_key
   SAMPLE_IMAGES_S3_SECRET=your_secret
   SAMPLE_IMAGES_S3_REGION=nyc3
   SAMPLE_IMAGES_S3_BUCKET=your-sample-images-bucket
   SAMPLE_IMAGES_S3_ENDPOINT=https://nyc3.digitaloceanspaces.com
   SAMPLE_IMAGES_S3_PATH_STYLE=true
   AUTO_DOWNLOAD_SAMPLE_IMAGES=true
   ```

3. **Commands**:
   ```bash
   # Download sample images from bucket
   php artisan proofgen:download-samples
   
   # Upload local sample images to bucket
   php artisan proofgen:upload-samples
   
   # Upload from a different directory
   php artisan proofgen:upload-samples --path=/path/to/images
   
   # Upload without overwriting existing files
   php artisan proofgen:upload-samples --no-overwrite
   ```

4. **Automatic downloading**:
   - Set `AUTO_DOWNLOAD_SAMPLE_IMAGES=true` to auto-download when tests run
   - Tests will be skipped if images aren't available and auto-download is disabled

## Testing

The application has a comprehensive test suite covering both unit and feature tests:

### Unit Tests
- **Image Class Tests**: Path parsing, file renaming, and movement during processing
- **ShowClass Tests**: Batch operations on image collections
- **Configuration Model Tests**: Database storage and retrieval of configuration values
- **Configuration Service Provider Tests**: Provider registration and boot process

### Feature Tests
- **Image Processing Workflow Test**: Full image processing workflow simulation

### Running Tests
```bash
# Run all tests
./vendor/bin/pest

# Run specific test files or groups
./vendor/bin/pest --filter="Proofgen"
./vendor/bin/pest --filter="ConfigurationTest"
./vendor/bin/pest --filter="ImageProcessingWorkflowTest"
```

## UI Framework

This project uses FluxUI - a UI framework for Laravel & Livewire. The documentation is included in the `external-docs/fluxui` directory. Start with `index.md` for comprehensive component documentation.

Customizations to FluxUI colors and components can be found in `/resources/css/app.css`.

## Code Style Guidelines

- **Formatting**: 4-space indentation, UTF-8 encoding, LF line endings
- **PHP Version**: 8.2+
- **Naming**: PascalCase for classes, camelCase for methods and variables
- **Types**: Use type hints for parameters and return types
- **Error Handling**: Use Laravel's exception handlers
- **Framework**: Follow Laravel conventions
- **Frontend**: Tailwind CSS 4.x, Livewire 4.x with Flux 2.x
- **Testing**: Pest for tests, use feature and unit tests appropriately

## Getting Started with Image Processing

1. Set `fullsize_home_dir` in Settings (or `FULLSIZE_HOME_DIR` before database configuration exists) to point to your images parent folder
2. Create a show folder (e.g., "20Buckeye") inside the FULLSIZE_HOME_DIR
3. Create class folders (e.g., "0001") inside the show folder
4. Place full-size images inside the class folders
5. Access the web interface to process the images

The system will detect the folders, process the images according to your configuration, and optionally upload them to the remote server.

## Configuration Management

The application is transitioning from `.env` file configuration to database-stored configuration via the `Configuration` model. This improves the user experience for non-technical users by providing a web interface for configuration management.

## Additional Documentation

For developers and AI assistants, see `CLAUDE.md` for important project instructions, `CLAUDE_NOTES.md` for detailed project notes and TODO items, and `docs/archive-backups.md` for the photo archive recovery contract.
