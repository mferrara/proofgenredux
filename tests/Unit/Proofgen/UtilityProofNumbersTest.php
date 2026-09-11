<?php

namespace Tests\Unit\Proofgen;

use App\Proofgen\Utility;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Utility::generateProofNumbers reads the originals folders for a show and
 * allocates the next block of proof numbers. These are synthetic-filesystem
 * regressions for the allocation and validation rules:
 *
 *  - the highest number is compared numerically (10000 > 9999),
 *  - the show prefix (uppercased) and five-digit zero-padding are preserved,
 *  - the count + 4 over-allocation is preserved,
 *  - non-image/hidden files are ignored (existing discovery),
 *  - any other JPEG original fails loudly instead of being skipped, and the
 *    original is left untouched.
 */
class UtilityProofNumbersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fullsize');
    }

    public function test_allocates_after_the_highest_number_across_all_classes(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_00007.jpg', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_00009.jpeg', 'x');
        Storage::disk('fullsize')->put('SHOW1/127/originals/SHOW1_00042.jpg', 'x');

        $this->assertSame(
            ['SHOW1_00043', 'SHOW1_00044', 'SHOW1_00045', 'SHOW1_00046'],
            Utility::generateProofNumbers('SHOW1', 0)
        );
    }

    public function test_compares_suffixes_numerically_not_lexicographically(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_9999.jpg', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_10000.jpg', 'x');

        $this->assertSame(
            ['SHOW1_10001', 'SHOW1_10002', 'SHOW1_10003', 'SHOW1_10004'],
            Utility::generateProofNumbers('SHOW1', 0)
        );
    }

    public function test_unpadded_suffixes_are_normalized_to_five_digits(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_42.jpg', 'x');

        $this->assertSame('SHOW1_00043', Utility::generateProofNumbers('SHOW1', 0)[0]);
    }

    public function test_show_prefix_is_uppercased_and_preserved(): void
    {
        Storage::disk('fullsize')->put('23r41/121/originals/23R41_00005.jpg', 'x');

        $this->assertSame('23R41_00006', Utility::generateProofNumbers('23r41', 0)[0]);
    }

    public function test_count_plus_four_over_allocation_is_preserved(): void
    {
        $numbers = Utility::generateProofNumbers('SHOW1', 3);

        $this->assertCount(7, $numbers);
        $this->assertSame('SHOW1_00001', $numbers[0]);
        $this->assertSame('SHOW1_00007', $numbers[6]);
    }

    public function test_non_image_and_hidden_files_are_ignored(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/notes.txt', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/notes-about-jpg.txt', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_99999.jpeg.backup', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/.DS_Store', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/IMG_1234.cr2', 'x');

        $this->assertSame('SHOW1_00001', Utility::generateProofNumbers('SHOW1', 0)[0]);
    }

    public function test_unexpected_jpeg_original_fails_loudly_and_is_retained(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/IMG_1234.jpg', 'x');

        try {
            Utility::generateProofNumbers('SHOW1', 10);
            $this->fail('Expected a descriptive exception for a non-proof JPEG in the originals folder.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHOW1', $e->getMessage());
            $this->assertStringContainsString('SHOW1/121/originals/IMG_1234.jpg', $e->getMessage());
        }

        $this->assertTrue(
            Storage::disk('fullsize')->exists('SHOW1/121/originals/IMG_1234.jpg'),
            'The offending original must be left in place.'
        );
    }

    public function test_ambiguous_numbered_original_is_not_silently_ignored_even_below_the_highest_number(): void
    {
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_00042 (1).jpg', 'x');
        Storage::disk('fullsize')->put('SHOW1/121/originals/SHOW1_99999.jpg', 'x');

        try {
            Utility::generateProofNumbers('SHOW1', 0);
            $this->fail('Expected a descriptive exception for an ambiguously numbered original.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHOW1/121/originals/SHOW1_00042 (1).jpg', $e->getMessage());
        }
    }
}
