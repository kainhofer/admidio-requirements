<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Tests\Support\AdmidioTestCase;

final class RequirementsPluginManifestTest extends AdmidioTestCase
{
    public function testManifestUsesNativePluginContract(): void
    {
        $pluginPath = dirname(__DIR__, 2);
        $plugin = Plugin::read($pluginPath);

        $this->assertTrue($plugin->isValid(), (string)$plugin->error);
        $this->assertSame('requirements', $plugin->id);
        $this->assertSame('0.2.0', $plugin->version);
        $this->assertSame(
            array('activities.php', 'index.php', 'providers.php', 'rules.php'),
            $plugin->getPages()
        );
        $normalize = static fn(?string $path): ?string => $path === null ? null : str_replace('\\', '/', $path);
        $this->assertSame(
            $normalize(realpath($pluginPath . '/src')),
            $normalize($plugin->autoload['AdmidioPlugin\\Requirements\\'] ?? null)
        );

        $access = $plugin->settings['requirements_plugin_enabled'] ?? array();
        $this->assertSame('enum', $access['type'] ?? null);
        $this->assertSame('2', $access['default'] ?? null);
        $this->assertSame(array('0', '2'), $access['values'] ?? null);

        $editPolicy = $plugin->settings['requirements_activity_owner_edit_policy'] ?? array();
        $this->assertSame('always', $editPolicy['default'] ?? null);
        $this->assertSame(array('always', 'until_submitted'), $editPolicy['values'] ?? null);

        $proration = $plugin->settings['requirements_proration_basis_default'] ?? array();
        $this->assertSame('month', $proration['default'] ?? null);
        $this->assertSame(array('month', 'day', 'week'), $proration['values'] ?? null);
    }
}
