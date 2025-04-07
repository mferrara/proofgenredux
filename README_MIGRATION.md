# ProofGen Directory Structure Migration Guide

This guide will help you migrate from the old directory structure to the new directory structure used in the latest version of ProofGen Redux.

## Directory Structure Changes

The application has updated how proof thumbnails and web images are stored:

### Old Structure
```
$FULLSIZE_HOME_DIR/show_name/class_name/proofs/show_name_00001_thm.jpg  # Small thumbnails
$FULLSIZE_HOME_DIR/show_name/class_name/proofs/show_name_00001_std.jpg  # Large thumbnails
$FULLSIZE_HOME_DIR/show_name/class_name/web_images/show_name_00001_web.jpg
```

### New Structure
```
$FULLSIZE_HOME_DIR/proofs/show_name/class_name/show_name_00001_thm.jpg  # Small thumbnails
$FULLSIZE_HOME_DIR/proofs/show_name/class_name/show_name_00001_std.jpg  # Large thumbnails
$FULLSIZE_HOME_DIR/web_images/show_name/class_name/show_name_00001_web.jpg
```

This change was made to improve synchronization with the web server and to organize proof and web images more effectively.

## Upgrade Steps

Follow these steps to safely update your ProofGen Redux installation:

### 1. Backup Your Data

Before proceeding, make sure to back up your data:

```bash
# Backup your photos directory
cp -R $FULLSIZE_HOME_DIR $FULLSIZE_HOME_DIR_backup
```

### 2. Update the Code

Pull the latest code from Git:

```bash
cd /path/to/proofgenredux
git pull
```

### 3. Install Dependencies

```bash
composer install
```

### 4. Run Database Migrations (if any)

```bash
php artisan migrate
```

### 5. Run the Migration Tool in Dry Run Mode

First, check what would be migrated without making any changes:

```bash
php artisan proofs:migrate --dry-run
```

This will scan your photo directories and show you what old-format files would be migrated.

### 6. Run the Migration Tool

Once you're satisfied with the dry run output, run the command without the dry-run flag:

```bash
# To copy files (keeping originals)
php artisan proofs:migrate

# OR to move files (deleting originals after successful copy)
php artisan proofs:migrate --move
```

## Migration Command Options

The `proofs:migrate` command accepts the following options:

- `--base-path=/your/photos/path` - Specify a custom base path (defaults to your FULLSIZE_HOME_DIR)
- `--dry-run` - Run without making any changes (preview mode)
- `--move` - Move files instead of copying them (saves disk space, removes originals, and cleans up empty directories)

## After Migration

- Check the log file in `storage/logs/migration_*.log` for details
- Verify that your shows and classes appear correctly in the application
- Test that proof and web images are accessible

## Troubleshooting

- If you encounter errors, check the log file for specific details
- The migration command is safe to run multiple times - it won't overwrite existing files
- If you need to revert, restore from your backup

## Note for Future Imports

After migration, the system will automatically use the new directory structure for all new imports. The migration is only needed for existing files that were imported with an older version of the application.
