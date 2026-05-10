<?php

namespace App\Services\Migration;

class RcloneShowCopyCommandBuilder
{
    /**
     * @return array<string, string>
     */
    public function commands(
        string $showSlug,
        string $rcloneRemote,
        string $bucket,
        string $sshHost,
        string $sshUser,
        bool $dryRun = false,
    ): array {
        return [
            'proofs' => $this->command(
                showSlug: $showSlug,
                sourceBase: (string) config('proofgen.sftp.path'),
                targetPrefix: 'proofs',
                rcloneRemote: $rcloneRemote,
                bucket: $bucket,
                sshHost: $sshHost,
                sshUser: $sshUser,
                dryRun: $dryRun,
            ),
            'web_images' => $this->command(
                showSlug: $showSlug,
                sourceBase: (string) config('proofgen.sftp.web_images_path'),
                targetPrefix: 'web_images',
                rcloneRemote: $rcloneRemote,
                bucket: $bucket,
                sshHost: $sshHost,
                sshUser: $sshUser,
                dryRun: $dryRun,
            ),
            'highres_images' => $this->command(
                showSlug: $showSlug,
                sourceBase: (string) config('proofgen.sftp.highres_images_path'),
                targetPrefix: 'highres_images',
                rcloneRemote: $rcloneRemote,
                bucket: $bucket,
                sshHost: $sshHost,
                sshUser: $sshUser,
                dryRun: $dryRun,
            ),
        ];
    }

    private function command(
        string $showSlug,
        string $sourceBase,
        string $targetPrefix,
        string $rcloneRemote,
        string $bucket,
        string $sshHost,
        string $sshUser,
        bool $dryRun,
    ): string {
        $source = rtrim($sourceBase, '/').'/'.$showSlug;
        $target = $rcloneRemote.':'.trim($bucket, '/').'/'.$targetPrefix.'/'.$showSlug;
        $dryRunFlag = $dryRun ? ' --dry-run' : '';

        $rclone = 'rclone copy --immutable --no-update-modtime --checksum'
            .$dryRunFlag
            .' '.escapeshellarg($source)
            .' '.escapeshellarg($target)
            .' --transfers 8 --checkers 16 --log-level INFO --stats 30s';

        return 'ssh '.escapeshellarg($sshUser.'@'.$sshHost).' '.escapeshellarg($rclone);
    }
}
