# Proofgen

### 2024

`brew install rsync` for modern version of rsync
laravel herd
+ redis (on herd)
+ raise memory limit on herd/php

## PHP Extensions required

fileinfo
exif
gd
pcntl

## Sample Images for Testing

The application uses a sample image system that can automatically download test images from an S3-compatible bucket (like Digital Ocean Spaces):

1. **Setup S3 bucket**:
   - Create a bucket for sample images (separate from production images)
   - Add appropriate sample files in the structure `{show}/{class}/{image.jpg}`
   - Configure S3 credentials in `.env`

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

## Getting new images into the system:

FULLSIZE_HOME_DIR="~/Desktop/ShowPhotos"

In order to get proofgen to process images it needs to know where to look for them. The way we do
this is by the FULLSIZE_HOME_DIR configuration variable in the .env file. Enter the full path
to the images PARENT folder here. So, if you have a directory "~/Desktop/ShowPhotos" where you have
"20Buckeye" - your FULLSIZE_HOME_DIR would be "~/Desktop/ShowPhotos". Proofgen will then detect
the "20Buckeye" folder as the "Show" folder (and this will be used as the proof number prefix).

Within this "20Buckeye" folder you'll want "class folders" which contains the full size images.
Example of this structure would be "~/Desktop/ShowPhotos/20Buckeye/0001" where the full size images
are inside the "0001" folder.

ARCHIVE_HOME_DIR="~/Desktop/fullsize_archive"

While proofgen processes full size images into proofs it will also copy the full size image to an
alternate file location - for backup purposes. This _should_ be an external drive, this is the back
up in case the main drive crashes/fails/laptop is stolen. It will copy the full size images to this
location with the same folder format as where they came from, the only difference being these fullsize
images will be renamed with the proof number.
