<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests privacy-safe content capture presets.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class CapturePolicyPresetFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['changelogify', 'node', 'contact'];

  /**
   * Tests recommended presets never enable privacy-sensitive entity types.
   */
  public function testRecommendedPresetKeepsSensitiveTypesDisabled(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/settings');
    $this->assertSession()->pageTextContains('Privacy warning');
    $this->assertSession()->checkboxNotChecked('content_capture[contact_message][enabled]');
    $this->submitForm([], 'Select all recommended');
    $this->assertSession()->checkboxNotChecked('content_capture[contact_message][enabled]');
    $this->assertSession()->checkboxChecked('content_capture[node][enabled]');

    $this->submitForm([], 'Clear all capture selections');
    $this->assertSession()->checkboxNotChecked('content_capture[node][enabled]');
    $this->assertSession()->checkboxNotChecked('content_capture[contact_message][enabled]');
  }

}
