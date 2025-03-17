# Proofgen Redux Project Notes

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

## Sample Images
The project includes sample images for testing:
- Located in `storage/sample_images/`
- Sample show: "2023R41"
- Sample classes: "121" and "127"
- Images named to simulate files directly out of the camera (e.g., "IMG_xxxxx.jpg")

These images represent an unprocessed state, ready to be processed by the proofing system. (Note: These are here mainly for use in testing, they should be left in this state for future testing, copied to another location _when_ testing to preserve state.)

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
