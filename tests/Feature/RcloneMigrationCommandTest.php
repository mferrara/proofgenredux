<?php

use App\Services\Migration\RcloneShowCopyCommandBuilder;
use App\Services\Migration\RcloneStatsParser;

it('builds ssh wrapped rclone commands for each legacy content root', function () {
    config([
        'proofgen.sftp.path' => '/home/forge/ferraraphoto.com/public/proofs',
        'proofgen.sftp.web_images_path' => '/home/forge/ferraraphoto.com/public/web_images',
        'proofgen.sftp.highres_images_path' => '/home/forge/ferraraphoto.com/public/highres_images',
    ]);

    $commands = app(RcloneShowCopyCommandBuilder::class)->commands(
        showSlug: '2026R12',
        rcloneRemote: 'b2-2026',
        bucket: 'ferraraphoto-2026',
        sshHost: 'ferraraphoto.com',
        sshUser: 'forge',
        dryRun: true,
    );

    expect($commands)->toHaveKeys(['proofs', 'web_images', 'highres_images'])
        ->and($commands['proofs'])->toContain('ssh')
        ->and($commands['proofs'])->toContain('rclone copy --immutable --no-update-modtime --checksum --dry-run')
        ->and($commands['proofs'])->toContain('/home/forge/ferraraphoto.com/public/proofs/2026R12')
        ->and($commands['proofs'])->toContain('b2-2026:ferraraphoto-2026/proofs/2026R12')
        ->and($commands['web_images'])->toContain('b2-2026:ferraraphoto-2026/web_images/2026R12')
        ->and($commands['highres_images'])->toContain('b2-2026:ferraraphoto-2026/highres_images/2026R12');
});

it('parses rclone stats without spawning rclone', function () {
    $output = <<<'TEXT'
Transferred:   	   17.123 MiB / 17.123 MiB, 100%, 0 B/s, ETA -
Transferred:           42 / 42, 100%
Errors:                 0
TEXT;

    $parsed = app(RcloneStatsParser::class)->parse($output, 0);

    expect($parsed)->toBe([
        'ok' => true,
        'errors' => 0,
        'transferred_files' => 42,
    ]);

    expect(app(RcloneStatsParser::class)->parse("Errors: 2\n", 0)['ok'])->toBeFalse()
        ->and(app(RcloneStatsParser::class)->parse("Errors: 0\n", 1)['ok'])->toBeFalse();
});
