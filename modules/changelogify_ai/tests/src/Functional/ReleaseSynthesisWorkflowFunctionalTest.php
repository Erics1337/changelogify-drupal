<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\changelogify_ai\SynthesisDraftFinalizer;
use Drupal\changelogify_ai\SynthesisQueueRunner;
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
    $this->assertSession()->addressMatches('/\/admin\/config\/development\/changelogify\/ai\/jobs\/[a-f0-9]{64}$/');
    $this->assertSession()->pageTextContains('Waiting for background processing');
    $this->assertSession()->elementExists('css', '[aria-live="polite"]');
    $this->assertSession()->elementExists('css', 'progress[max][value]');
    $this->assertSession()->pageTextContains('Status updates automatically');
    $this->assertSession()->pageTextContains('No recent processor heartbeat');
    $this->assertSession()->pageTextContains('A site administrator manages background processing');
    $this->assertSession()->elementExists('css', '[data-job-stage="queued"][aria-current="step"]');
    $this->assertSession()->elementExists('css', '[data-job-queue]');
    $this->assertSession()->pageTextContains('Automatic updates require JavaScript');

    $jobs = $this->container->get(SynthesisJobManager::class)->all();
    self::assertCount(1, $jobs);
    $job = reset($jobs);
    self::assertSame('concise', $job['profile']);
    self::assertSame('detailed', $job['length_preset']);
    $internal = $this->container->get(SynthesisJobManager::class)->get($job['id']);
    self::assertCount(1, $internal['source_index']);
    self::assertCount(1, $internal['coverage_exclusions']['editor']);

    $this->drainSynthesisQueue();
    $finalized = $this->container->get(SynthesisJobManager::class)->get($job['id']);
    self::assertSame('finalized', $finalized['status']);
    self::assertIsInt($finalized['release_id']);
    self::assertArrayNotHasKey('final_result', $finalized);
    self::assertArrayNotHasKey('finalization_context', $finalized);
    $release = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release')
      ->load($finalized['release_id']);
    self::assertFalse($release->isPublished());
    self::assertSame('draft', $release->getEditorialState());
    self::assertSame($job['id'], $release->getProvenance()['synthesis']['job_id']);
    self::assertSame(1, $release->getProvenance()['coverage']['evidence_considered']);
    self::assertSame(1, $release->getProvenance()['coverage']['excluded_by_editor']);
    $releaseCount = $this->releaseCount();
    self::assertNull($this->container->get(SynthesisDraftFinalizer::class)->finalizeIfReady($job['id']));
    self::assertSame($releaseCount, $this->releaseCount());
  }

  /**
   * Evidence added after the reviewed preview fails without a partial release.
   */
  public function testStaleEvidenceFailsFinalizationSafely(): void {
    $this->prepareSynthesisEditor();
    $events = $this->container->get(EventManagerInterface::class);
    $events->logEvent([
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => 'Reviewed evidence.',
      'section_hint' => 'changed',
    ]);
    $jobId = $this->queueDefaultSynthesis();
    $events->logEvent([
      'event_type' => 'content_created',
      'source' => 'content_entity',
      'message' => 'Evidence added after preview.',
      'section_hint' => 'added',
    ]);

    $this->drainSynthesisQueue();
    $job = $this->container->get(SynthesisJobManager::class)->get($jobId);
    self::assertSame('failed', $job['status']);
    self::assertSame('stale_evidence', $job['error_code']);
    self::assertSame(0, $this->releaseCount());
    self::assertArrayNotHasKey('final_result', $job);
    self::assertArrayNotHasKey('finalization_context', $job);
  }

  /**
   * Cancellation makes queued references inert and creates no release.
   */
  public function testCancelledSynthesisCreatesNoRelease(): void {
    $this->prepareSynthesisEditor();
    $this->container->get(EventManagerInterface::class)->logEvent([
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => 'Cancelled evidence.',
      'section_hint' => 'changed',
    ]);
    $jobId = $this->queueDefaultSynthesis();
    $this->container->get(SynthesisJobManager::class)->cancel($jobId);

    $this->drainSynthesisQueue();
    self::assertSame('cancelled', $this->container->get(SynthesisJobManager::class)->get($jobId)['status']);
    self::assertSame(0, $this->releaseCount());
  }

  /**
   * A creator can view and cancel their job without global history access.
   */
  public function testCreatorAccessAndCrossUserDenial(): void {
    $creator = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($creator);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->set('eligibility.categories', ['content'])
      ->save();
    $this->container->get(EventManagerInterface::class)->logEvent([
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => 'Owner access evidence.',
      'section_hint' => 'changed',
    ]);
    $jobId = $this->queueDefaultSynthesis();

    $statusPath = "/admin/config/development/changelogify/ai/jobs/{$jobId}/status";
    $this->drupalGet($statusPath);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertSame('waiting', $payload['state']);
    self::assertSame('unavailable', $payload['queue']['state']);
    self::assertSame(1, $payload['queue']['queued_steps']);
    self::assertNull($payload['queue']['processing_url']);
    self::assertArrayNotHasKey('evidence', $payload);
    self::assertArrayNotHasKey('instructions', $payload);

    $other = $this->drupalCreateUser(['use changelogify ai', 'access administration pages']);
    $this->drupalLogin($other);
    $this->drupalGet($statusPath);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($creator);
    $this->drupalGet("/admin/config/development/changelogify/ai/history/{$jobId}/cancel");
    $this->submitForm([], 'Confirm');
    $this->assertSession()->addressEquals("/admin/config/development/changelogify/ai/jobs/{$jobId}");
    $this->assertSession()->pageTextContains('AI synthesis was cancelled');
  }

  /**
   * A prohibited provenance mutation rolls back the deterministic shell.
   */
  public function testInvalidProvenanceRollsBackReleaseAndFailsJob(): void {
    $this->prepareSynthesisEditor();
    $this->container->get(EventManagerInterface::class)->logEvent([
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => 'Rollback evidence.',
      'section_hint' => 'changed',
    ]);
    $jobId = $this->queueDefaultSynthesis();
    $queue = $this->container->get('queue')->get(SynthesisJobManager::QUEUE_NAME);
    $reference = $queue->claimItem();
    self::assertNotFalse($reference);
    $this->container->get(SynthesisJobManager::class)->process(
      $reference->data['job_id'],
      $reference->data['batch_id'],
    );
    $queue->deleteItem($reference);
    $store = $this->container->get('keyvalue')->get('changelogify_ai.synthesis_jobs');
    $job = $store->get($jobId);
    self::assertSame('completed', $job['status']);
    $job['provenance']['provider_payload'] = ['secret' => 'must-not-persist'];
    $store->set($jobId, $job);

    self::assertNull($this->container->get(SynthesisDraftFinalizer::class)->finalizeIfReady($jobId));
    $failed = $this->container->get(SynthesisJobManager::class)->get($jobId);
    self::assertSame('failed', $failed['status']);
    self::assertSame(0, $this->releaseCount());
    self::assertArrayNotHasKey('provider_payload', $failed);
    self::assertArrayNotHasKey('provenance', $failed);
  }

  /**
   * Processes all recursively created synthesis queue references.
   */
  private function drainSynthesisQueue(): void {
    $summary = $this->container->get(SynthesisQueueRunner::class)->run(10, 100, 30);
    self::assertSame(0, $summary['failed']);
    self::assertFalse($summary['suspended']);
    self::assertSame(0, $summary['remaining']);
  }

  /**
   * Creates and signs in an editor with a ready deterministic test provider.
   */
  private function prepareSynthesisEditor(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'view changelogify ai history',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->set('eligibility.categories', ['content'])
      ->save();
  }

  /**
   * Queues the current exact default synthesis boundary through the editor UI.
   */
  private function queueDefaultSynthesis(): string {
    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm(['mode' => 'since_last'], 'Preview changes');
    $this->submitForm([
      'ai_synthesis[profile]' => 'public_product',
      'ai_synthesis[length_preset]' => 'standard',
    ], 'Create AI draft release');
    $jobs = $this->container->get(SynthesisJobManager::class)->all();
    self::assertCount(1, $jobs);
    return (string) array_key_first($jobs);
  }

  /**
   * Counts all draft and published release entities without access filtering.
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
