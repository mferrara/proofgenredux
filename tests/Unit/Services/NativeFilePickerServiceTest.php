<?php

namespace Tests\Unit\Services;

use App\Services\NativeFilePickerService;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class NativeFilePickerServiceTest extends TestCase
{
    public function test_pick_folder_returns_path_from_osascript_output(): void
    {
        Process::fake([
            '*' => Process::result(output: "/Users/mikeferrara/Pictures/shows\n", exitCode: 0),
        ]);

        $picker = new NativeFilePickerService;
        $result = $picker->pickFolder('/Users/mikeferrara');

        $this->assertSame('/Users/mikeferrara/Pictures/shows', $result);
        Process::assertRan(function ($pendingProcess) {
            $command = $pendingProcess->command;
            $script = is_array($command) ? $command[2] ?? '' : '';

            return str_contains($script, 'choose folder');
        });
    }

    public function test_pick_folder_returns_null_when_user_cancels(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: '38:62: execution error: User canceled. (-128)',
                exitCode: 1
            ),
        ]);

        $result = (new NativeFilePickerService)->pickFolder();

        $this->assertNull($result);
    }

    public function test_pick_file_includes_of_type_filter_in_script(): void
    {
        Process::fake([
            '*' => Process::result(output: "/Users/mikeferrara/.ssh/id_rsa\n", exitCode: 0),
        ]);

        (new NativeFilePickerService)->pickFile(
            initialPath: '/Users/mikeferrara/.ssh',
            prompt: 'Select your SSH private key',
            ofType: ['pem', 'key']
        );

        Process::assertRan(function ($pendingProcess) {
            $script = $pendingProcess->command[2] ?? '';

            return str_contains($script, 'choose file')
                && str_contains($script, 'of type {"pem", "key"}')
                && str_contains($script, 'with prompt "Select your SSH private key"');
        });
    }

    public function test_pick_folder_throws_when_osascript_returns_non_cancel_error(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'something went wrong',
                exitCode: 1
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('osascript failed');

        (new NativeFilePickerService)->pickFolder();
    }

    public function test_pick_folder_strips_trailing_slash_and_validates_absolute_path(): void
    {
        Process::fake([
            '*' => Process::result(output: "/Users/mikeferrara/shows/\n", exitCode: 0),
        ]);

        $result = (new NativeFilePickerService)->pickFolder();

        $this->assertSame('/Users/mikeferrara/shows', $result);
    }
}
