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
}
