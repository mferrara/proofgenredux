# Proofgen Redux Project Notes

## TODO items
- [ ] Make favicon from the logo
- [ ] Update web image and highres image uploads to happen _after_ the proofs are uploaded to ensure that the proofs are prioritized for upload
- [ ] Implement something that is able to report the current filesize/storage usage of the following at the show and class levels:
  - [ ] Fullsize images
  - [ ] Proofs
  - [ ] Web images
  - [ ] Highres images
  - [ ] Combined total
- [ ] Implement a way to determine and report the storage usage of the /backups directory
- [ ] Implement a way to determine and report the storage usage of the /storage/sample_images directory
- [ ] Add a "Download Sample Images" button to the configuration page

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
     - Creates archive copies (configurable)
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
     - Thumbnails/proofs stored in separate subfolder, sibling to "originals" named "proofs"
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
