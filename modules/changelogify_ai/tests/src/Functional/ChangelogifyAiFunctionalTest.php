<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\changelogify\EventManagerInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests editor-facing Changelogify AI integration states.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
#[RunTestsInSeparateProcesses]
final class ChangelogifyAiFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['changelogify', 'ai', 'changelogify_ai'];

  /**
   * Tests known missing prerequisites disable generation with a direct action.
   */
  public function testNotReadyGenerationAction(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->container->get(EventManagerInterface::class)->logEvent([
      'event_type' => 'content_updated',
      'source' => 'test',
      'message' => 'AI readiness evidence',
      'section_hint' => 'changed',
    ]);

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm(['mode' => 'since_last'], 'Preview changes');

    $this->assertSession()->pageTextContains('permission to process selected release evidence has not been granted');
    $this->assertSession()->linkExists('Configure Changelogify AI');
    $this->assertSession()->buttonExists('Create AI draft release');
    $this->assertSession()->elementAttributeContains(
      'css',
      'input[value="Create AI draft release"]',
      'disabled',
      'disabled',
    );
  }

}
