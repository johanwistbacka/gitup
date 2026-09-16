<?php

declare(strict_types=1);

final class GitupPluginReactivationTest extends GitupTestCase
{
    private const PLUGIN = 'example/example.php';

    public function test_inactive_plugin_is_not_activated(): void
    {
        $result = gitup_reactivate_plugin_after_release_install(self::PLUGIN, false);

        $this->assertTrue($result);
        $this->assertCount(0, $GLOBALS['gitup_test_activate_plugin_calls']);
    }

    public function test_plugin_that_remains_active_is_not_activated_again(): void
    {
        $GLOBALS['gitup_test_active_plugins'] = [self::PLUGIN];

        $result = gitup_reactivate_plugin_after_release_install(self::PLUGIN, true);

        $this->assertTrue($result);
        $this->assertCount(0, $GLOBALS['gitup_test_activate_plugin_calls']);
    }

    public function test_previously_active_plugin_is_reactivated(): void
    {
        $result = gitup_reactivate_plugin_after_release_install(self::PLUGIN, true);

        $this->assertTrue($result);
        $this->assertTrue(is_plugin_active(self::PLUGIN));
        $this->assertCount(1, $GLOBALS['gitup_test_activate_plugin_calls']);
    }

    public function test_activation_error_is_returned_as_partial_failure(): void
    {
        $activation_error = new WP_Error('plugin_error', 'Test activation failure.');
        $GLOBALS['gitup_test_activate_plugin_result'] = $activation_error;

        $result = gitup_reactivate_plugin_after_release_install(self::PLUGIN, true);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_plugin_reactivation_failed', $result->get_error_code());
        $this->assertStringContainsString('could not be reactivated', $result->get_error_message());
        $this->assertStringContainsString('Test activation failure.', $result->get_error_message());
        $this->assertSame($activation_error, $result->get_error_data());
        $this->assertFalse(is_plugin_active(self::PLUGIN));
        $this->assertCount(1, $GLOBALS['gitup_test_activate_plugin_calls']);
    }

    public function test_inactive_state_after_successful_activation_call_is_reported(): void
    {
        $GLOBALS['gitup_test_activate_plugin_marks_active'] = false;

        $result = gitup_reactivate_plugin_after_release_install(self::PLUGIN, true);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('gitup_plugin_reactivation_failed', $result->get_error_code());
        $this->assertStringContainsString('could not be reactivated', $result->get_error_message());
        $this->assertFalse(is_plugin_active(self::PLUGIN));
        $this->assertCount(1, $GLOBALS['gitup_test_activate_plugin_calls']);
    }
}
