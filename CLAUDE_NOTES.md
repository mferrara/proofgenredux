# Proofgen Redux Project Notes

## Stack
- Laravel 13.x · Livewire 4.x · Flux 2.x · Tailwind 4.x · PHP 8.4 · Pest 4.x · Tinker 3.x · Intervention Image 4.x

## Reference docs
- **Photo pipeline**: `docs/photo-pipeline.md` — end-to-end flowchart of how a JPG moves from ingest folder through resolver/archive/originals/derivatives/upload, with decision matrices, service catalog, key invariants, and known follow-ups. **Read this first when changing anything in the import or audit pipeline.** §15 lists the sharp edges worth knowing about (upload-parser fragility, reset-photos rough edges, audit walk performance, etc.).
- **Ferraraphoto integration**: `docs/FERRARAPHOTO_INTEGRATION.md` — sister Laravel 4.2 app at `/Users/mikeferrara/Documents/code/ferraraphoto`; rsync-coupled by show slug.
- **Archive backups**: `docs/archive-backups.md` — archive copy semantics + audit/repair workflows.

## TODO items
- [x] Complete the queued image-output fixes alongside the file-safety and UI rounds (2026-09-12). See [show-prep checklist](docs/SHOW_PREP_TODO.md) and [image-output review](docs/reviews/2026-09-12-image-output-fixes.md).
- [ ] Run the small end-to-end import/output/upload rehearsal after compiling and refreshing the local Core Image daemon. Existing thumbnails need regeneration to receive image-output changes.
- [x] Consolidate artisan commands that aren't in the proofgen namespace into the proofgen namespace (renamed `swift:compile` -> `proofgen:swift-compile`, `swift:check` -> `proofgen:swift-check`, `coreimage:daemon` -> `proofgen:coreimage-daemon`, `proofs:migrate` -> `proofgen:migrate-proofs`)
- [x] Make favicon from the logo (generated 16/32/180/192/512 PNGs + multi-res ICO from the purple-orb portion of `application-logo.blade.php`; source SVG at `resources/svg/favicon-source.svg`, outputs in `public/`, referenced from all three layouts)
- [x] Update web image and highres image uploads to happen _after_ the proofs are uploaded to ensure that the proofs are prioritized for upload (chained at the class level via Bus::chain in 2026-05; show-level upload is synchronous and already proofs-first)
- [x] Storage usage reporting at show + class level (originals, proofs, web, highres, archive, total) — `App\Services\StorageUsageService`; lazy-loaded panels on `ShowViewComponent` + `ClassViewComponent`. Cached 10 min.
- [x] /backups + storage/sample_images directory sizing — same service (`backupsUsage()`, `sampleImagesUsage()`); panel on `HomeComponent`.
- [x] "Download Sample Images" button on configuration page — `ConfigComponent::downloadSampleImages()` with progress toast.

## Development Environment Access Information

### File System Access
- Base filesystem path: `/Users/mikeferrara/Herd/proofgenredux`
- SQLite database path: `/Users/mikeferrara/Herd/proofgenredux/database/database.sqlite`

### Tool Access
- SQLite MCP Server: Available through the following functions:
  - `list_tables`: Lists all tables in the SQLite database
  - `describe_table`: Gets schema information for a specific table
  - `read_query`: Executes SELECT queries on the database
  - `write_query`: Executes INSERT, UPDATE, or DELETE queries
  - `create_table`: Creates new tables in the database

## Application Overview
This is a Laravel application that processes event photography images for a photography sales platform. Key functionality:

1. **Image Processing Workflow**:
   - Monitors directories with full-size images from photographers
   - Processes images through multiple steps:
     - Renames files with proof numbers (configurable)
     - Creates verified archive copies with per-photo archive metadata (configurable)
     - Generates thumbnails with watermarks (configurable)
     - Creates web-optimized versions (configurable) (this web-optimized version is what we call a "web image" and it's a paid product, rather than the customer ordering a printed photograph, this is effectively a digital copy of their image sans watermarks and at a quality and size that can be used for social media purposes)
     - Uploads proofs & web image to remote server via SFTP/rsync (configurable)

2. **Main Components**:
   - **Livewire Components**:
     - `HomeComponent`: Directory navigation
     - `ShowViewComponent`: Show-level operations
     - `ClassViewComponent`: Class-level operations 
     - `ConfigComponent`: Configuration management
   - **Core Classes**:
     - `Show`: Represents a photography event
     - `ShowClass`: Represents a class within a show
     - `Image`: Handles image processing
   - **Background Jobs**:
     - `ImportPhoto`: Process single image
     - `GenerateThumbnails`: Create thumbnails
     - `GenerateWebImage`: Create web versions
     - `UploadProofs`: Upload to remote server
     - `ImportPhotos`: Batch process images

3. **Directory Structure**:
   - Base path: Configured in `fullsize_home_dir`/`FULLSIZE_HOME_DIR` (`.env`)
   - Show folders: Named for events (e.g., "2023R41")
   - Class folders: Named for classes within shows (e.g., "121", "127")
   - Within each class folder:
     - Raw images placed directly in class folder for processing
     - After processing, renamed images moved to "originals" subfolder
   - Generated proofs, web images, and highres images are stored in separate top-level trees under `proofs/{show}/{class}`, `web_images/{show}/{class}`, and `highres_images/{show}/{class}`.
   - This "proofs" folder is in the exact structure expected by the remote server
   - The remote server will expect the files within the "proofs" folder to be placed within the /{show_id}/{class_id}/ directory

4. **Configuration**:
   - Moving from `.env` files to database storage
   - `Configuration` model handles storage and retrieval
   - Aims to improve UX for non-technical users

## Sample Images System

The project now includes a robust sample image handling system:

### Remote Sample Images Storage

- Sample images are stored in an S3-compatible bucket (separate from production images)
- This allows everyone working on the project to access the same testing data
- Using Digital Ocean Spaces for cost-effective storage

### Directory Structure

- Sample images follow the same structure as expected by the application:
  ```
  {show_id}/{class_id}/IMG_xxxxx.jpg
  ```
- Standard sample data includes:
  - Show: "2023R41"
  - Classes: "121" and "127"
  - Images named to simulate files directly from camera (e.g., "IMG_xxxxx.jpg")

### Automatic Download Feature

- Tests can automatically download sample images when needed
- This keeps sample images out of version control while ensuring tests have necessary data
- Configure with `AUTO_DOWNLOAD_SAMPLE_IMAGES=true` in `.env`

### Management Commands

- Download images: `php artisan proofgen:download-samples`
- Upload images: `php artisan proofgen:upload-samples`
- Upload from custom path: `php artisan proofgen:upload-samples --path=/path/to/images`
- Upload without overwriting: `php artisan proofgen:upload-samples --no-overwrite`

## Photo Archive Backup System

- Archive backups are local second copies of imported originals, usually pointed at a fast external drive through `archive_home_dir`.
- Database-backed `fullsize_home_dir` and `archive_home_dir` values are applied to the `fullsize` and `archive` filesystem disks at runtime.
- Import writes the archive copy before deleting the ingest source file from the class folder.
- Import records `photos.sha1` from the same bytes used for the original/archive writes, instead of relying on `Photo::created` to reread the moved file.
- `photos` records track `archive_path`, `archive_sha1`, `archive_size`, and `archived_at`.
- Archive writes are idempotent: identical existing files are reused, while different existing files are moved into the class `_conflicts` folder before the current copy is written.
- Class renames, selected photo moves, and class resets move archive files so backups reflect the current recoverable show/class layout.
- Audit and repair command: `php artisan proofgen:audit-archives`; add `--repair` to backfill missing or mismatched archive files from local originals.

### Implementation Details

- Uses Laravel's storage system with S3 disk configuration
- `SampleImagesService` handles checking for, downloading, and uploading sample images
- Tests gracefully skip when images aren't available and auto-download is disabled
- Allows tests to run in CI environments without needing the images

### Environment Configuration

```
# Sample Images S3 Configuration
SAMPLE_IMAGES_S3_KEY=your_key
SAMPLE_IMAGES_S3_SECRET=your_secret
SAMPLE_IMAGES_S3_REGION=nyc3
SAMPLE_IMAGES_S3_BUCKET=your-sample-images-bucket
SAMPLE_IMAGES_S3_ENDPOINT=https://nyc3.digitaloceanspaces.com
SAMPLE_IMAGES_S3_PATH_STYLE=true
AUTO_DOWNLOAD_SAMPLE_IMAGES=true
```

## Testing Setup

The application has a comprehensive test suite covering both unit and feature tests:

### Unit Tests

1. **Image Class Tests (`tests/Unit/Proofgen/ImageTest.php`)**
   - Tests path parsing, file renaming, and movement during processing
   - Uses file system fakes to avoid actual disk operations
   - Tests configuration-dependent behavior (e.g., filename preservation)

2. **ShowClass Tests (`tests/Unit/Proofgen/ShowClassTest.php`)**
   - Tests batch operations on image collections
   - Mocks the Utility class for directory operations
   - Verifies proper job dispatching for batched operations

3. **Configuration Model Tests (`tests/Unit/Models/ConfigurationTest.php`)**
   - Tests database storage and retrieval of configuration values
   - Verifies type casting based on configuration type
   - Tests cache behavior for configuration values
   - Ensures configuration can override Laravel config values

4. **Configuration Service Provider Tests (`tests/Unit/Providers/ConfigurationServiceProviderTest.php`)**
   - Tests provider registration and boot process
   - Verifies that database configurations are properly loaded

### Feature Tests

1. **Image Processing Workflow Test (`tests/Feature/ImageProcessingWorkflowTest.php`)**
   - Tests the full image processing workflow
   - Directly dispatches jobs to simulate the Livewire component actions
   - Verifies proper job dispatching during different stages

### Test Helpers

1. **Mocking Approach**
   - File system operations are mocked using Laravel's Storage facade
   - External services (Redis) are mocked using Mockery
   - Utility class that handles file operations is mocked
   - Job dispatching is tested using Laravel's Bus and Queue fakes

### Running Tests

```bash
# Run all tests
./vendor/bin/pest

# Run specific test files or groups
./vendor/bin/pest --filter="Proofgen"
./vendor/bin/pest --filter="ConfigurationTest"
./vendor/bin/pest --filter="ImageProcessingWorkflowTest"
```
