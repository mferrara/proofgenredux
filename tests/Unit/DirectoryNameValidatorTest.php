<?php

namespace Tests\Unit;

use App\Helpers\DirectoryNameValidator;
use PHPUnit\Framework\TestCase;

class DirectoryNameValidatorTest extends TestCase
{
    public function test_valid_directory_names(): void
    {
        $this->assertTrue(DirectoryNameValidator::isValid('class1'));
        $this->assertTrue(DirectoryNameValidator::isValid('class_123'));
        $this->assertTrue(DirectoryNameValidator::isValid('class-456'));
        $this->assertTrue(DirectoryNameValidator::isValid('123'));
        $this->assertTrue(DirectoryNameValidator::isValid('a_b_c'));
    }

    public function test_invalid_directory_names_with_spaces(): void
    {
        $this->assertFalse(DirectoryNameValidator::isValid('class 1'));
        $this->assertFalse(DirectoryNameValidator::isValid('my class'));
        $this->assertFalse(DirectoryNameValidator::isValid(' class'));
        $this->assertFalse(DirectoryNameValidator::isValid('class '));
    }

    public function test_invalid_directory_names_with_special_characters(): void
    {
        $this->assertFalse(DirectoryNameValidator::isValid('class<1'));
        $this->assertFalse(DirectoryNameValidator::isValid('class>1'));
        $this->assertFalse(DirectoryNameValidator::isValid('class:1'));
        $this->assertFalse(DirectoryNameValidator::isValid('class"1'));
        $this->assertFalse(DirectoryNameValidator::isValid('class|1'));
        $this->assertFalse(DirectoryNameValidator::isValid('class?1'));
        $this->assertFalse(DirectoryNameValidator::isValid('class*1'));
    }

    public function test_invalid_directory_names_with_dots(): void
    {
        $this->assertFalse(DirectoryNameValidator::isValid('.hidden'));
        $this->assertFalse(DirectoryNameValidator::isValid('class.'));
        $this->assertTrue(DirectoryNameValidator::isValid('class.123')); // dots in middle are ok
    }

    public function test_invalid_reserved_names(): void
    {
        $this->assertFalse(DirectoryNameValidator::isValid('CON'));
        $this->assertFalse(DirectoryNameValidator::isValid('con'));
        $this->assertFalse(DirectoryNameValidator::isValid('PRN'));
        $this->assertFalse(DirectoryNameValidator::isValid('AUX'));
        $this->assertFalse(DirectoryNameValidator::isValid('COM1'));
        $this->assertFalse(DirectoryNameValidator::isValid('LPT1'));
    }

    public function test_validation_error_messages(): void
    {
        $this->assertEquals(
            'Directory name contains spaces. Use hyphens or underscores instead.',
            DirectoryNameValidator::getValidationError('class 1')
        );
        $this->assertEquals(
            "Directory name contains invalid character: '<'",
            DirectoryNameValidator::getValidationError('class<1')
        );
        $this->assertEquals(
            'Directory name cannot start or end with a dot.',
            DirectoryNameValidator::getValidationError('.hidden')
        );
        $this->assertEquals(
            'Directory name is a reserved system name.',
            DirectoryNameValidator::getValidationError('CON')
        );
    }

    public function test_suggest_valid_names(): void
    {
        $this->assertEquals('class_1', DirectoryNameValidator::suggestValidName('class 1'));
        $this->assertEquals('my_class', DirectoryNameValidator::suggestValidName('my class'));
        $this->assertEquals('class1', DirectoryNameValidator::suggestValidName('class<1'));
        $this->assertEquals('hidden', DirectoryNameValidator::suggestValidName('.hidden'));
        $this->assertEquals('CON_class', DirectoryNameValidator::suggestValidName('CON'));
    }

    /**
     * The website stores the folder name as the class number. A folder it
     * rejects could be proofed here but never uploaded, and used to block the
     * uploads of the whole show.
     */
    public function test_names_the_website_would_reject_are_invalid_and_get_a_usable_suggestion(): void
    {
        $cases = [
            'Halter_' => 'Halter',
            '_private' => 'private',
            "O'Brien_Class" => 'O-Brien_Class',
            'Hunter & Jumper (Open)' => 'Hunter_and_Jumper_Open',
            'Walk/Trot #12' => 'Walk-Trot_12',
            'a..b' => 'a.b',
            'Western Pleasure Junior Horse Championship Finals 2026' => 'Western_Pleasure_Junior_Horse_Ch',
            '...' => 'class',
        ];

        foreach ($cases as $name => $suggestion) {
            $this->assertFalse(DirectoryNameValidator::isValid($name), $name.' should be rejected');
            $this->assertNotNull(DirectoryNameValidator::getValidationError($name), $name.' needs an explanation');
            $this->assertSame($suggestion, DirectoryNameValidator::suggestValidName($name));
            $this->assertTrue(DirectoryNameValidator::isValid($suggestion), 'The suggestion for '.$name.' must itself be valid');
        }

        foreach (['005', '012-A.1', 'Halter', 'A', str_repeat('a', 32)] as $name) {
            $this->assertTrue(DirectoryNameValidator::isValid($name), $name.' should be accepted');
        }

        $this->assertFalse(DirectoryNameValidator::isValid(str_repeat('a', 33)));
    }
}
