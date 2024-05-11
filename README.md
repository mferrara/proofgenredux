# Proofgen

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

## Process new images found:

Processes all new images found in the base show/class directories. The images will be renamed, thumbnailed, watermarked and uploaded.

php artisan proofgen:process

## Rebuild thumbnails/upload to website

This will remove all existing thumbnails, re-producing and uploading them

php artisan proofgen:regenerate

## Process all errors, attempting to complete the failed jobs

php artisan proofgen:errors

### Setup on Mac OS/OSX

First, we need to get a version of PHP with GD image manipulation support installed.

This process comes from https://php-osx.liip.ch/ - refer here for additional information or changes

First, run this (This installs PHP 7.1)

`curl -s https://php-osx.liip.ch/install.sh | bash -s 7.1`

Then, edit ~/.bash_profile to add the following (This makes sure we're using that version of PHP on the command line)

`export PATH=/usr/local/php5/bin:$PATH`

Then, run this

`source ~/.bash_profile`

Then, run this to determine which version of PHP we're running (preferably not 5.5)

`php -v`

You should see something like this...

```
proofgen git:(master) ✗ php -v
PHP 7.1.9 (cli) (built: Sep 14 2017 10:05:35) ( NTS )
Copyright (c) 1997-2017 The PHP Group
Zend Engine v3.1.0, Copyright (c) 1998-2017 Zend Technologies
    with Zend OPcache v7.1.9, Copyright (c) 1999-2017, by Zend Technologies
    with Xdebug v2.5.3, Copyright (c) 2002-2017, by Derick Rethans

```

Now we should have a modern version of PHP with the required extensions to process and upload images.
