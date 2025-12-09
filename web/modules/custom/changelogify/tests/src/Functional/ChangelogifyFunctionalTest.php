<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Changelogify admin interface.
 *
 * @group changelogify
 */
class ChangelogifyFunctionalTest extends BrowserTestBase
{

    /**
     * {@inheritdoc}
     */
    protected $defaultTheme = 'stark';

    /**
     * {@inheritdoc}
     */
    protected static $modules = ['changelogify', 'node', 'user'];

    /**
     * Tests that the dashboard page loads.
     */
    public function testDashboardAccess(): void
    {
        $user = $this->drupalCreateUser([
            'administer changelogify',
            'manage changelogify releases',
            'access administration pages',
        ]);

        $this->drupalLogin($user);

        // Visit the dashboard.
        $this->drupalGet('/admin/config/development/changelogify');
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->pageTextContains('Changelogify');

        // Visit the release list.
        $this->drupalGet('/admin/content/changelogify/releases');
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->pageTextContains('Releases');
    }
}
