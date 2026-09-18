<?php

namespace App\Services;

use App\Helpers\DirectoryNameValidator;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Proofgen\Utility;

/**
 * What still has to happen for each class of a show, in the order the work
 * flows: photos waiting in the folder -> imported -> proofs/web/highres
 * generated -> uploaded. The command line (and any agent helping the
 * photographer) reads this instead of scraping the pages.
 */
class ShowWorkSummary
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function classes(Show $show): array
    {
        $known = $show->classes()->get()->keyBy('name');
        $folders = array_map('basename', Utility::getDirectoriesOfPath($show->id));
        $names = array_values(array_unique(array_merge($folders, $known->keys()->all())));
        natcasesort($names);

        $issues = PhotoIssue::open()->where('show_id', $show->id)
            ->selectRaw('show_class_id, COUNT(*) as c')->groupBy('show_class_id')->pluck('c', 'show_class_id');

        $rows = [];
        foreach ($names as $name) {
            if (str_starts_with($name, '.')) {
                continue;
            }

            $class = $known->get($name);
            $valid = DirectoryNameValidator::isValid($name);
            $waiting = in_array($name, $folders, true)
                ? count(Utility::getContentsOfPath($show->id.'/'.$name, false)['images'] ?? [])
                : 0;

            $rows[] = [
                'class' => $name,
                'name_ok' => $valid,
                'name_problem' => $valid ? null : DirectoryNameValidator::getValidationError($name).' Suggested: '.DirectoryNameValidator::suggestValidName($name),
                'waiting_to_import' => $waiting,
                'imported' => $class?->photos()->count() ?? 0,
                'proofs_to_generate' => $class?->photosNotProofed()->count() ?? 0,
                'web_to_generate' => $class?->photosNotWebImaged()->count() ?? 0,
                'highres_to_generate' => $class?->photosNotHighresImaged()->count() ?? 0,
                'proofs_to_upload' => $class?->photosProofedNotUploaded()->count() ?? 0,
                'web_to_upload' => $class?->photosWebImagedNotUploaded()->count() ?? 0,
                'highres_to_upload' => $class?->photosHighresImagedNotUploaded()->count() ?? 0,
                'open_issues' => (int) ($issues[$show->id.'_'.$name] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function nextStep(array $row): string
    {
        return match (true) {
            ! $row['name_ok'] => 'rename the folder',
            $row['open_issues'] > 0 => 'review issues',
            $row['waiting_to_import'] > 0 => 'import',
            $row['proofs_to_generate'] + $row['web_to_generate'] + $row['highres_to_generate'] > 0 => 'generating (wait, or check the workers)',
            $row['proofs_to_upload'] > 0 => 'upload (proofs waiting)',
            $row['web_to_upload'] + $row['highres_to_upload'] > 0 => 'upload (web/highres waiting)',
            $row['imported'] === 0 => 'empty',
            default => 'done',
        };
    }
}
