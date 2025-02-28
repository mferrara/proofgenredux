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
