<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify_ai\PromptTemplateRegistry;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the immediate, single-request release synthesis workflow.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
#[RunTestsInSeparateProcesses]
final class ReleaseSynthesisWorkflowFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc} */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc} */
  protected static $modules = [
    'changelogify',
    'ai',
    'changelogify_ai',
    'changelogify_ai_test',
  ];

  /**
   * Generate sends the reviewed boundary once and redirects to the draft.
   */
  public function testGenerateImmediatelyCreatesOneUnpublishedDraft(): void {
    $this->prepareSynthesisEditor(['content', 'configuration']);
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
    $this->assertSession()->pageTextContains('Exact AI evidence preview (2 considered');
    $this->assertSession()->pageTextContains('one provider request immediately');
    $this->assertSession()->elementExists(
      'css',
      'input[name="ai_synthesis[length_preset]"][value="auto"][checked]',
    );

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

    $this->assertSession()->pageTextContains('Your AI-synthesized draft is ready for review');
    $this->assertSession()->elementExists(
      'css',
      'form[data-changelogify-release-editor][data-changelogify-release-mode="preview"]',
    );
    $this->assertSession()->pageTextContains('1 summary note synthesized from 1 eligible change group.');
    $this->assertSession()->buttonExists('Preview changelog');
    $this->assertSession()->buttonExists('Edit summary notes');
    $this->assertSession()->pageTextContains('Supporting evidence — 1 tracked change');
    $jobs = $this->container->get(SynthesisJobManager::class)->all();
    self::assertCount(1, $jobs);
    $job = reset($jobs);
    self::assertSame('finalized', $job['status']);
    self::assertSame(1, $job['attempt_count']);
    self::assertSame(0, $job['retry_count']);
    self::assertSame('concise', $job['profile']);
    self::assertSame('detailed', $job['length_preset']);
    self::assertSame(1, $job['coverage']['evidence_considered']);
    self::assertSame(1, $job['coverage']['excluded_by_editor']);
    self::assertIsInt($job['release_id']);
    $release = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release')
      ->load($job['release_id']);
    self::assertFalse($release->isPublished());
    self::assertSame('draft', $release->getEditorialState());
    self::assertSame($job['id'], $release->getProvenance()['synthesis']['job_id']);
    self::assertSame(1, $this->releaseCount());
  }

  /**
   * Prepared status is owner-only and contains no queue or evidence payload.
   */
  public function testCreatorStatusAndCancellationRemainPrivacyBounded(): void {
    $creator = $this->prepareSynthesisEditor(['content'], FALSE);
    $jobId = $this->container->get(SynthesisJobManager::class)->startResult(
      [
        'change-1' => [
          'id' => 'change-1',
          'section' => 'changed',
          'summary' => 'Safe reviewed evidence.',
        ],
      ],
      'public_product',
      SynthesisContract::PRESET_AUTO,
      PromptTemplateRegistry::VERSION,
      'policy-test',
      'eligibility-test',
      actor: (int) $creator->id(),
    )->jobId;

    $statusPath = "/admin/config/development/changelogify/ai/jobs/{$jobId}/status";
    $this->drupalGet($statusPath);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertSame('preparing', $payload['state']);
    self::assertSame(['completed' => 0, 'total' => 1], $payload['progress']);
    self::assertSame('Auto', $payload['details']['length']);
    self::assertArrayNotHasKey('queue', $payload);
    self::assertArrayNotHasKey('evidence', $payload);
    self::assertArrayNotHasKey('instructions', $payload);

    $other = $this->drupalCreateUser(['use changelogify ai', 'access administration pages']);
    $this->drupalLogin($other);
    $this->drupalGet($statusPath);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($creator);
    $this->drupalGet("/admin/config/development/changelogify/ai/history/{$jobId}/cancel");
    $this->submitForm([], 'Confirm');
    $this->assertSession()->pageTextContains('AI synthesis was cancelled');
    self::assertSame('cancelled', $this->container->get(SynthesisJobManager::class)->get($jobId)['status']);
    self::assertSame(0, $this->releaseCount());
  }

  /**
   * Creates and signs in an editor with the deterministic fake provider.
   */
  private function prepareSynthesisEditor(array $categories, bool $history = TRUE): object {
    $permissions = [
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ];
    if ($history) {
      $permissions[] = 'view changelogify ai history';
    }
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->set('eligibility.categories', $categories)
      ->save();
    return $user;
  }

  /**
   * Counts all release entities without access filtering.
   */
  private function releaseCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
