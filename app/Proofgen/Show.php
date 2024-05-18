<?php

namespace App\Proofgen;

use Illuminate\Support\Facades\Log;

class Show
{
    protected string $show_folder = '';
    protected string $fullsize_base_path = '';
    protected string $proofs_path = '';
    protected string $remote_proofs_path = '';
    protected string $web_images_path = '';
    protected string $remote_web_images_path = '';

    public function __construct(string $show_folder)
    {
        $this->show_folder = $show_folder;
        $this->fullsize_base_path = config('proofgen.fullsize_home_dir');
        $this->proofs_path = '/proofs/'.$this->show_folder;
        $this->remote_proofs_path = '/'.$this->show_folder;
        $this->web_images_path = '/web_images/'.$this->show_folder;
        $this->remote_web_images_path = '/'.$this->show_folder;
    }

    public function rsyncProofsCommand($dry_run = false): string
    {
        $local_full_path = $this->fullsize_base_path.$this->proofs_path.'/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.$local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.path').$this->remote_proofs_path;
    }

    public function rsyncWebImagesCommand($dry_run = false): string
    {
        $local_full_path = $this->fullsize_base_path.$this->web_images_path.'/';
        $dry_run = $dry_run === true ? '--dry-run' : '';

        return 'rsync -avz --delete '.$dry_run.' -e "ssh -i '.config('proofgen.sftp.private_key').'" '.$local_full_path.' forge@'.config('proofgen.sftp.host').':'.config('proofgen.sftp.web_images_path').$this->remote_web_images_path;
    }

    public function pendingProofUploads(): array
    {
        Log::debug('pendingProofUploads');
        /*
        $remote_filesystem = Utility::remoteFilesystem(config('proofgen.sftp.path'));
        if( ! $remote_filesystem->has($this->remote_proofs_path)) {
            $remote_filesystem->createDirectory($this->remote_proofs_path);
        }
        */
        $command = $this->rsyncProofsCommand(true);
        exec($command, $output, $returnCode);

        $pending_proofs = [];
        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            )
                continue;

            if (!empty($line) && str_ends_with(strtolower($line), strtolower('.jpg'))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $pending_proofs[] = $this->proofs_path.'/'.$parts[0].'/'.$fileName;
                }
            }
        }

        return $pending_proofs;
    }

    public function uploadPendingProofs(): array
    {
        $command = $this->rsyncProofsCommand();
        exec($command, $output, $returnCode);

        $uploaded_proofs = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            ) {
                continue;
            }

            if (!empty($line) && str_ends_with(strtolower($line), strtolower('.jpg'))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $uploaded_proofs[] = $this->proofs_path.'/'.$parts[0].'/'.$fileName;
                }
            }
        }

        return $uploaded_proofs;
    }

    public function pendingWebImageUploads(): array
    {
        Log::debug('pendingWebImageUploads');
        /*
        $remote_filesystem = Utility::remoteFilesystem(config('proofgen.sftp.web_images_path'));
        if( ! $remote_filesystem->has($this->remote_web_images_path)) {
            $remote_filesystem->createDirectory($this->remote_web_images_path);
        }
        */

        $command = $this->rsyncWebImagesCommand(true);
        exec($command, $output, $returnCode);

        $pending_web_images = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            )
                continue;

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $pending_web_images[] = $this->web_images_path.'/'.$fileName;
                }
            }
        }

        return $pending_web_images;
    }

    public function uploadPendingWebImages(): array
    {
        $command = $this->rsyncWebImagesCommand();
        exec($command, $output, $returnCode);

        $uploaded_web_images = [];

        foreach ($output as $line) {
            $line = trim($line);

            if(str_starts_with(strtolower($line), '.')
                || str_ends_with(strtolower($line), '/')
                || str_starts_with(strtolower($line), 'deleting')
            ) {
                continue;
            }

            if (!empty($line) && str_starts_with(strtolower($line), strtolower($this->show_folder))) {
                $parts = explode('/', $line);
                $fileName = end($parts);

                if (!empty($fileName) && strpos($fileName, '.') !== false) {
                    $uploaded_web_images[] = $this->web_images_path.'/'.$fileName;
                }
            }
        }

        return $uploaded_web_images;
    }
}
