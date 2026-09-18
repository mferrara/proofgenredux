<?php

namespace Tests\Unit\Services;

use App\Services\SafeDirectory;
use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\UnableToCreateDirectory;
use Mockery;
use Tests\TestCase;

/**
 * Parallel import workers create the same brand-new class folder at the same
 * moment; losing that race must not fail the import.
 */
class SafeDirectoryTest extends TestCase
{
    public function test_losing_the_race_to_another_worker_is_not_a_failure(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('makeDirectory')->once()->with('26AAC/005')
            ->andThrow(UnableToCreateDirectory::atLocation('26AAC/005', 'mkdir(): File exists'));
        $disk->shouldReceive('directoryExists')->once()->with('26AAC/005')->andReturnTrue();

        SafeDirectory::ensure($disk, '26AAC/005');

        $this->addToAssertionCount(1);
    }

    public function test_it_retries_while_the_other_worker_is_still_creating_the_parents(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('makeDirectory')->twice()->with('26AAC/005')
            ->andReturnUsing(
                fn () => throw UnableToCreateDirectory::atLocation('26AAC/005', 'mkdir(): File exists'),
                fn () => true,
            );
        $disk->shouldReceive('directoryExists')->once()->andReturnFalse();

        SafeDirectory::ensure($disk, '26AAC/005');

        $this->addToAssertionCount(1);
    }

    public function test_a_directory_that_really_cannot_be_created_still_fails(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('makeDirectory')->times(3)
            ->andThrow(UnableToCreateDirectory::atLocation('26AAC/005', 'mkdir(): Permission denied'));
        $disk->shouldReceive('directoryExists')->times(3)->andReturnFalse();

        $this->expectException(UnableToCreateDirectory::class);

        SafeDirectory::ensure($disk, '26AAC/005');
    }

    public function test_an_empty_path_is_ignored(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldNotReceive('makeDirectory');

        SafeDirectory::ensure($disk, '');
        SafeDirectory::ensure($disk, '.');

        $this->addToAssertionCount(1);
    }
}
