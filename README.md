# Proofgen Redux

A Laravel application for processing event photography images, managing watermarks, and uploading to a photography sales platform.

## Overview

Proofgen Redux monitors directories containing full-size images from photographers and processes them through a configurable workflow:

1. **Image Processing Steps**:
   - Renames files with proof numbers (configurable)
   - Creates archive copies (configurable)
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
│   │   ├── originals/    # Renamed images moved here after processing
│   │   └── proofs/       # Generated thumbnails/proofs
```

### Configuration
- **FULLSIZE_HOME_DIR**: Base directory containing show folders
- **ARCHIVE_HOME_DIR**: Backup location for full-size images (ideally external drive)

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
- **Frontend**: Tailwind CSS 4.x, Livewire 3.x with Flux
- **Testing**: Pest for tests, use feature and unit tests appropriately

## Getting Started with Image Processing

1. Set `FULLSIZE_HOME_DIR` in your `.env` file to point to your images parent folder
2. Create a show folder (e.g., "20Buckeye") inside the FULLSIZE_HOME_DIR
3. Create class folders (e.g., "0001") inside the show folder
4. Place full-size images inside the class folders
5. Access the web interface to process the images

The system will detect the folders, process the images according to your configuration, and optionally upload them to the remote server.

## Configuration Management

The application is transitioning from `.env` file configuration to database-stored configuration via the `Configuration` model. This improves the user experience for non-technical users by providing a web interface for configuration management.

## Additional Documentation

For developers and AI assistants, see `CLAUDE.md` for important project instructions and `CLAUDE_NOTES.md` for detailed project notes and TODO items.