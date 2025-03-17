<?php

namespace Tests\Unit\Models;

use App\Models\Configuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config as LaravelConfig;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before tests
        Cache::flush();
    }

    /**
     * Test that configuration can be created and retrieved
     */
    public function test_configuration_can_be_set_and_retrieved()
    {
        // Create a test configuration
        Configuration::setConfig(
            'test.key', 
            'test_value', 
            'string', 
            'testing', 
            'Test Config', 
            'A test configuration value'
        );
        
        // Retrieve it
        $value = Configuration::getConfig('test.key');
        
        // Check the value
        $this->assertEquals('test_value', $value);
        
        // Check that the record was properly saved
        $this->assertDatabaseHas('configurations', [
            'key' => 'test.key',
            'value' => 'test_value',
            'type' => 'string',
            'category' => 'testing',
            'label' => 'Test Config',
            'description' => 'A test configuration value'
        ]);
    }
    
    /**
     * Test that values are properly cast based on type
     */
    public function test_configuration_values_are_properly_cast()
    {
        // Create configurations with different types
        Configuration::setConfig('test.string', 'test', 'string');
        Configuration::setConfig('test.integer', '123', 'integer');
        Configuration::setConfig('test.boolean_true', '1', 'boolean');
        Configuration::setConfig('test.boolean_false', '0', 'boolean');
        Configuration::setConfig('test.float', '123.45', 'float');
        Configuration::setConfig('test.array', 'one,two,three', 'array');
        Configuration::setConfig('test.json', '{"key":"value"}', 'json');
        
        // Retrieve and check each type
        $this->assertSame('test', Configuration::getConfig('test.string'));
        $this->assertSame(123, Configuration::getConfig('test.integer'));
        $this->assertSame(true, Configuration::getConfig('test.boolean_true'));
        $this->assertSame(false, Configuration::getConfig('test.boolean_false'));
        $this->assertSame(123.45, Configuration::getConfig('test.float'));
        $this->assertEquals(['one', 'two', 'three'], Configuration::getConfig('test.array'));
        $this->assertEquals(['key' => 'value'], Configuration::getConfig('test.json'));
    }
    
    /**
     * Test that configuration is cached
     */
    public function test_configuration_is_cached()
    {
        Configuration::setConfig('test.cached', 'original', 'string');
        
        // Get the value (first retrieval should cache it)
        $value = Configuration::getConfig('test.cached');
        $this->assertEquals('original', $value);
        
        // Update the value directly in the database without clearing cache
        Configuration::where('key', 'test.cached')->update(['value' => 'updated']);
        
        // The cached value should still be returned
        $value = Configuration::getConfig('test.cached');
        $this->assertEquals('original', $value);
        
        // Clear the cache
        Cache::forget(Configuration::CONFIGURATIONS_SINGLE_VALUE_KEY.'.test.cached');
        
        // Now should get the updated value
        $value = Configuration::getConfig('test.cached');
        $this->assertEquals('updated', $value);
    }
    
    /**
     * Test the compile method properly builds the config array
     */
    public function test_compile_builds_config_array()
    {
        // Create several test configurations
        Configuration::setConfig('test1', 'value1', 'string');
        Configuration::setConfig('test2', '123', 'integer');
        Configuration::setConfig('test3', 'true', 'boolean');
        
        // Compile the configurations
        $compiled = Configuration::compile();
        
        // Check the compiled array
        $this->assertIsArray($compiled);
        $this->assertArrayHasKey('test1', $compiled);
        $this->assertArrayHasKey('test2', $compiled);
        $this->assertArrayHasKey('test3', $compiled);
        $this->assertEquals('value1', $compiled['test1']);
        $this->assertEquals(123, $compiled['test2']);
        $this->assertEquals(true, $compiled['test3']);
    }
    
    /**
     * Test that configuration overrides Laravel config values
     */
    public function test_configuration_overrides_application_config()
    {
        // Set up an initial config value
        LaravelConfig::set('proofgen.test_override', 'original');
        
        // Create a database configuration
        Configuration::setConfig('test_override', 'overridden', 'string');
        
        // Run the override process
        Configuration::overrideApplicationConfig();
        
        // Check if the value was overridden
        $this->assertEquals('overridden', LaravelConfig::get('proofgen.test_override'));
    }
}