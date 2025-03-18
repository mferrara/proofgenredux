<?php

namespace Tests\Unit\Models;

use App\Models\Configuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConfigurationPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before tests
        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * Test that database values take precedence over config values
     */
    public function test_database_values_take_precedence_over_config_values()
    {
        // Set a value in the Laravel config (simulating .env)
        Config::set('proofgen.test_key', 'env_value');
        
        // Set a different value in the database
        Configuration::setConfig('test_key', 'db_value', 'string');
        
        // Apply the configuration overrides
        Configuration::overrideApplicationConfig();
        
        // Check that the database value takes precedence
        $this->assertEquals('db_value', Config::get('proofgen.test_key'));
    }

    /**
     * Test that config values are used when no database value exists
     */
    public function test_config_values_are_used_when_no_database_value_exists()
    {
        // Set a value in the Laravel config (simulating .env)
        Config::set('proofgen.only_in_env', 'env_only_value');
        
        // Apply the configuration overrides
        Configuration::overrideApplicationConfig();
        
        // Check that the config value is still used
        $this->assertEquals('env_only_value', Config::get('proofgen.only_in_env'));
    }

    /**
     * Test that updating database values updates the config
     */
    public function test_updating_database_values_updates_config()
    {
        // Set an initial value in config
        Config::set('proofgen.update_test', 'initial_value');
        
        // Set a different value in the database
        $config = Configuration::setConfig('update_test', 'first_db_value', 'string');
        
        // Apply the configuration overrides
        Configuration::overrideApplicationConfig();
        
        // Check the initial override
        $this->assertEquals('first_db_value', Config::get('proofgen.update_test'));
        
        // Update the database value
        $config->value = 'updated_db_value';
        $config->save();
        
        // Force reload configurations
        Configuration::overrideApplicationConfig();
        
        // Check that the updated database value takes precedence
        $this->assertEquals('updated_db_value', Config::get('proofgen.update_test'));
    }

    /**
     * Test that the configuration service provider properly loads configuration overrides
     */
    public function test_configuration_service_provider_loads_overrides()
    {
        // Set a value in the Laravel config (simulating .env)
        Config::set('proofgen.provider_test', 'env_value');
        
        // Set a different value in the database
        Configuration::setConfig('provider_test', 'db_value', 'string');
        
        // Create a provider instance directly
        $provider = new \App\Providers\ConfigurationServiceProvider($this->app);
        $provider->boot();
        
        // Check that the database value takes precedence
        $this->assertEquals('db_value', Config::get('proofgen.provider_test'));
    }

    /**
     * Test that boolean casting works correctly with the improved implementation
     */
    public function test_boolean_casting_works_correctly()
    {
        // True values
        $this->assertTrue(Configuration::castValue('true', 'boolean'));
        $this->assertTrue(Configuration::castValue('TRUE', 'boolean'));
        $this->assertTrue(Configuration::castValue('1', 'boolean'));
        $this->assertTrue(Configuration::castValue('yes', 'boolean'));
        $this->assertTrue(Configuration::castValue('y', 'boolean'));
        $this->assertTrue(Configuration::castValue('on', 'boolean'));
        
        // False values
        $this->assertFalse(Configuration::castValue('false', 'boolean'));
        $this->assertFalse(Configuration::castValue('FALSE', 'boolean'));
        $this->assertFalse(Configuration::castValue('0', 'boolean'));
        $this->assertFalse(Configuration::castValue('no', 'boolean'));
        $this->assertFalse(Configuration::castValue('n', 'boolean'));
        $this->assertFalse(Configuration::castValue('off', 'boolean'));
        
        // Other values
        $this->assertTrue(Configuration::castValue('anything else', 'boolean'));
        $this->assertFalse(Configuration::castValue('', 'boolean'));
    }

    /**
     * Test that numeric values are handled correctly for comparison
     */
    public function test_numeric_values_compare_correctly()
    {
        // Set a value in the Laravel config (simulating .env)
        Config::set('proofgen.numeric_test', 123); // Use actual integer instead of string
        
        // Set a database value that's the same but a different type (int 123 vs string "123")
        Configuration::setConfig('numeric_test', '123', 'integer');
        
        // Apply the configuration overrides
        Configuration::overrideApplicationConfig();
        
        // Check that the config value is properly cast and compared
        $this->assertSame(123, Config::get('proofgen.numeric_test'));
        
        // Now set a contradicting value
        Configuration::setConfig('numeric_test', '456', 'integer');
        Configuration::overrideApplicationConfig();
        
        // Check that the database value takes precedence
        $this->assertSame(456, Config::get('proofgen.numeric_test'));
    }
    
    /**
     * Test the complete configuration priority hierarchy
     */
    public function test_configuration_priority_hierarchy()
    {
        // 1. Clear cache
        \Illuminate\Support\Facades\Cache::flush();
        
        // 2. Setup test values
        // Set .env equivalent (Laravel config)
        Config::set('proofgen.priority_test', 'env_value');
        
        // 3. At this point, only .env value exists
        $this->assertEquals('env_value', Config::get('proofgen.priority_test'));
        
        // 4. Add database value
        Configuration::setConfig('priority_test', 'db_value', 'string');
        
        // 5. Apply configuration overrides
        Configuration::overrideApplicationConfig();
        
        // 6. Database value should override .env value
        $this->assertEquals('db_value', Config::get('proofgen.priority_test'));
        
        // 7. Update database value
        $config = Configuration::where('key', 'priority_test')->first();
        $config->value = 'updated_db_value';
        $config->save();
        
        // Clear cache again
        \Illuminate\Support\Facades\Cache::flush();
        
        // Apply configuration overrides again
        Configuration::overrideApplicationConfig();
        
        // 8. Updated database value should be used
        $this->assertEquals('updated_db_value', Config::get('proofgen.priority_test'));
        
        // 9. Delete database value
        $config->delete();
        
        // Clear cache again
        \Illuminate\Support\Facades\Cache::flush();
        
        // Reset the configuration repository to ensure we're starting fresh
        Config::set('proofgen.priority_test', 'env_value');
        
        // Force a compile of configuration (this should now exclude the deleted record)
        Configuration::compile();
        
        // Apply configuration overrides again
        Configuration::overrideApplicationConfig();
        
        // 10. Original .env value should be used since database record is gone
        $this->assertEquals('env_value', Config::get('proofgen.priority_test'));
    }
}