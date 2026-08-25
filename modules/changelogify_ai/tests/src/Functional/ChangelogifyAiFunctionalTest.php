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

  /**
   * Tests explicit consent copy, preview access, and the request gate.
   */
  public function testProcessingConsentExplanationAndControl(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/ai');
    $this->assertSession()->pageTextContains('only the filtered evidence selected for an AI action');
    $this->assertSession()->pageTextContains('Cloud providers may process that data outside this Drupal site');
    $this->assertSession()->pageTextContains('Changelogify never receives provider credentials');
    $this->assertSession()->pageTextContains('does not delete previously accepted release revisions');
    $this->assertSession()->linkExists('Preview the filtered information shared with the AI provider');
    $this->assertSession()->checkboxNotChecked('processing_consent[consent_external_processing]');

    $this->submitForm([
      'processing_consent[consent_external_processing]' => TRUE,
    ], 'Save configuration');
    self::assertTrue((bool) $this->config('changelogify_ai.settings')
      ->get('consent_external_processing'));

    $this->submitForm([
      'processing_consent[consent_external_processing]' => FALSE,
    ], 'Save configuration');
    self::assertFalse((bool) $this->config('changelogify_ai.settings')
      ->get('consent_external_processing'));
  }

}
