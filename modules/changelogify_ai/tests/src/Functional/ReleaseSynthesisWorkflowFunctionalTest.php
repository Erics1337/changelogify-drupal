<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the AI-first release synthesis request workflow.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
#[RunTestsInSeparateProcesses]
final class ReleaseSynthesisWorkflowFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'changelogify',
    'ai',
    'changelogify_ai',
    'changelogify_ai_test',
  ];

  /**
   * The reviewed filtered boundary, profile, and length reach the queued job.
   */
  public function testAllEligibleEvidenceAndOptionalExclusionPreview(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'view changelogify ai history',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->set('eligibility.categories', ['content', 'configuration'])
      ->save();
    $events = $this->container->get(EventManagerInterface::class);
    $events->logEvent([
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => 'Customer page improved.',
      'section_hint' => 'changed',
    ]);
    $events->logEvent([
      'event_type' => 'config_updated',
      'source' => 'config',
      'message' => 'Search configuration improved.',
      'section_hint' => 'changed',
    ]);

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm(['mode' => 'since_last'], 'Preview changes');
    $this->assertSession()->pageTextContains('Create an AI-synthesized release');
    $this->assertSession()->pageTextContains('every site-eligible change');
    $this->assertSession()->pageTextContains('Exact AI evidence preview (2 considered');
    $this->assertSession()->pageTextContains('Site-wide eligibility decides what may be considered');
    $this->assertSession()->fieldExists('ai_synthesis[length_preset]');

    $this->submitForm([
      'ai_synthesis[profile]' => 'concise',
      'ai_synthesis[length_preset]' => 'detailed',
      'ai_synthesis[exclusions][categories][configuration]' => 'configuration',
    ], 'Create AI draft release');
    $this->assertSession()->pageTextContains('one-time exclusions changed after the evidence preview');
    self::assertSame([], $this->container->get(SynthesisJobManager::class)->all());

    $this->submitForm([
      'ai_synthesis[exclusions][categories][configuration]' => 'configuration',
    ], 'Update AI evidence preview');
    $this->assertSession()->pageTextContains('Exact AI evidence preview (1 considered, 1 excluded for this run');

    $this->submitForm([
      'ai_synthesis[profile]' => 'concise',
      'ai_synthesis[length_preset]' => 'detailed',
      'ai_synthesis[exclusions][categories][configuration]' => 'configuration',
    ], 'Create AI draft release');
    $this->assertSession()->addressEquals('/admin/config/development/changelogify/ai/history');
    $this->assertSession()->pageTextContains('AI release synthesis');

    $jobs = $this->container->get(SynthesisJobManager::class)->all();
    self::assertCount(1, $jobs);
    $job = reset($jobs);
    self::assertSame('concise', $job['profile']);
    self::assertSame('detailed', $job['length_preset']);
    $internal = $this->container->get(SynthesisJobManager::class)->get($job['id']);
    self::assertCount(1, $internal['source_index']);
    self::assertCount(1, $internal['coverage_exclusions']['editor']);
  }

}
