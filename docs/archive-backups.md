# Photo Archive Backups

Proofgen's archive backup is a local second copy of imported originals. It is designed for horse-show field work where internet is too slow for cloud originals, and a fast external drive is the practical independent filesystem.

## Contract

- The archive root is `archive_home_dir`, exposed as the `archive` filesystem disk.
- Database-backed `fullsize_home_dir` and `archive_home_dir` settings are applied to the `fullsize` and `archive` disks at runtime.
- Import writes and verifies the archive copy before deleting the ingest source file from the class folder.
- Import records `photos.sha1` from the same source bytes used for the original and archive writes; duplicate detection should use this import-time hash rather than depending on model events to reread the moved file.
- `photos.archive_path`, `photos.archive_sha1`, `photos.archive_size`, and `photos.archived_at` record the expected backup copy.
- Existing identical archive files are reused.
- Existing different archive files are moved into `_conflicts` under the class archive folder before the current copy is written.
- Class renames, selected photo moves, and class resets move archive files so the archive reflects the current recoverable class layout.
- UI photo deletion does not delete archive files.

## Layout

Archive paths mirror current show/class organization, without the `originals` folder:

```text
ARCHIVE_HOME_DIR/
└── {show}/
    └── {class}/
        ├── {proof_number}.jpg
        └── _conflicts/
            └── {proof_number}_{timestamp}_{sha1}.jpg
```

## Audit And Repair

The current command is `proofgen:audit` (the old `proofgen:audit-archives` name
no longer exists). It audits originals, archives and related file/identity issues.
It can create/update `photo_issues` records even without `--repair`.
**The declared `--persist-issues=false` option is currently ignored by the
implementation; do not use it as a read-only guarantee.** This is tracked in
[show-prep follow-ups](SHOW_PREP_TODO.md).

To deliberately audit after changing drives, recovering a machine, or importing
older pre-archive photos:

```bash
php artisan proofgen:audit
```

Limit the scan:

```bash
php artisan proofgen:audit --show=2023R41
php artisan proofgen:audit --show=2023R41 --class=121
```

Repair missing or mismatched archive files from local originals and refresh photo archive metadata:

```bash
php artisan proofgen:audit --repair
```

Use JSON for scripting:

```bash
php artisan proofgen:audit --format=json
```

The repair command cannot recreate a backup when both the local original and archive file are missing. In that case it reports the photo as needing attention.
