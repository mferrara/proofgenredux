<?php

namespace Tests\Unit\Providers;

use App\Models\Configuration;
use App\Providers\ConfigurationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConfigurationServiceProviderTest extends TestCase
{
    use RefreshDatabase;
    
    /**
     * Test that the ConfigurationServiceProvider registers properly
     */
    public function test_configuration_service_provider_registers()
    {
        $provider = new ConfigurationServiceProvider($this->app);
        
        // Just call register to ensure it doesn't throw any exceptions
        $provider->register();
        
        // This test just verifies the provider registers without errors
        $this->assertTrue(true);
    }
    
    /**
     * Test that the provider loads configurations during boot
     */
    public function test_configuration_service_provider_boots_and_loads_configurations()
    {
        // Create test configurations in the database
        Configuration::setConfig('test_key', 'test_value', 'string');
        
        // Clear config cache if any
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Call the boot method on a new provider instance
        $provider = new ConfigurationServiceProvider($this->app);
        $provider->boot();
        
        // Verify that the configuration was loaded into the application config
        $this->assertEquals('test_value', Config::get('proofgen.test_key'));
    }
    
    /**
     * Test that configuration updates trigger reload
     */
    public function test_configuration_updates_trigger_reload()
    {
        // Create initial configuration
        Configuration::setConfig('dynamic_key', 'initial_value', 'string');
        
        // Boot the service provider to load the configurations
        $provider = new ConfigurationServiceProvider($this->app);
        $provider->boot();
        
        // Verify initial config value
        $this->assertEquals('initial_value', Config::get('proofgen.dynamic_key'));
        
        // Update the configuration value
        Configuration::setConfig('dynamic_key', 'updated_value', 'string');
        
        // The update should set a flag in cache
        $this->assertTrue(Configuration::checkUpdateRequired());
        
        // Boot another instance to simulate application reload
        $newProvider = new ConfigurationServiceProvider($this->app);
        $newProvider->boot();
        
        // Verify updated config value is loaded
        $this->assertEquals('updated_value', Config::get('proofgen.dynamic_key'));
    }
}