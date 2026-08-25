<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests inline and release-wide AI review in the release editor.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
#[RunTestsInSeparateProcesses]
final class ReleaseAiEditorialFunctionalTest extends BrowserTestBase {

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
   * Tests defaults, temporary instructions, staging, and partial acceptance.
   */
  public function testWholeReleaseReviewAndPartialAcceptance(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->save();
    $release = $this->createRelease('draft');
    $startingRevision = (int) $release->getRevisionId();

    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->pageTextContains('Improve entire release with AI');
    $this->assertSession()->pageTextContains('Every eligible current note is selected by default.');
    $this->assertSession()->checkboxChecked('ai_workspace[items][item-1]');
    $this->assertSession()->checkboxChecked('ai_workspace[items][item-2]');
    $this->assertSession()->pageTextContains('Improve with AI');
    $this->assertSession()->pageTextContains('Temporary for this attempt and not saved as configuration.');

    $this->submitForm([
      'ai_workspace[profile]' => 'public_product',
      'ai_workspace[instructions]' => 'Focus on customer benefit.',
      'ai_workspace[items][item-1]' => 'item-1',
      'ai_workspace[items][item-2]' => 'item-2',
    ], 'Generate release suggestions');
    $this->assertSession()->pageTextContains('Review suggestions before applying');
    $this->assertSession()->pageTextContains('Current wording');
    $this->assertSession()->pageTextContains('Suggested wording');
    $this->assertSession()->buttonExists('Use selected suggestions');
    $this->assertSession()->buttonExists('Use all suggestions');
    $this->assertSession()->buttonExists('Try again');
    $this->assertSession()->buttonExists('Dismiss all suggestions');

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $storage->resetCache([(int) $release->id()]);
    self::assertSame($startingRevision, (int) $storage->load($release->id())->getRevisionId());

    $this->submitForm([
      'ai_workspace[review][item-1][accept]' => TRUE,
      'ai_workspace[review][item-2][accept]' => FALSE,
    ], 'Use selected suggestions');
    $storage->resetCache([(int) $release->id()]);
    $updated = $storage->load($release->id());
    self::assertGreaterThan($startingRevision, (int) $updated->getRevisionId());
    self::assertSame('First current note', $updated->getSections()['changed'][0]['text']);
    self::assertSame('Second current note', $updated->getSections()['changed'][1]['text']);
  }

  /**
   * Tests generation and dismissal never mutate a published release.
   */
  public function testPublishedPreviewAndDismissRemainPubliclyUnchanged(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->save();
    $release = $this->createRelease('published');
    $startingRevision = (int) $release->getRevisionId();

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'ai_workspace[profile]' => 'concise',
      'ai_workspace[instructions]' => 'Prepare a second review.',
      'ai_workspace[items][item-1]' => 'item-1',
      'ai_workspace[items][item-2]' => 'item-2',
    ], 'Generate release suggestions');
    $this->submitForm([], 'Dismiss all suggestions');

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $storage->resetCache([(int) $release->id()]);
    $unchanged = $storage->load($release->id());
    self::assertSame($startingRevision, (int) $unchanged->getRevisionId());
    self::assertSame('published', $unchanged->getEditorialState());
    $this->assertSession()->pageTextContains('The release was not changed.');

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'ai_workspace[profile]' => 'concise',
      'ai_workspace[instructions]' => 'Prepare accepted review wording.',
      'ai_workspace[items][item-1]' => 'item-1',
      'ai_workspace[items][item-2]' => 'item-2',
    ], 'Generate release suggestions');
    $this->submitForm([], 'Use all suggestions');
    $storage->resetCache([(int) $release->id()]);
    $publicDefault = $storage->load($release->id());
    self::assertSame($startingRevision, (int) $publicDefault->getRevisionId());
    self::assertSame('published', $publicDefault->getEditorialState());
    $revisionIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $release->id())
      ->allRevisions()
      ->execute();
    self::assertGreaterThan(1, count($revisionIds));
    $this->assertSession()->pageTextContains('non-public review revision');
  }

  /**
   * Tests per-item generation, comparison, and dismissal stay inline.
   */
  public function testInlineItemSuggestionReview(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->save();
    $release = $this->createRelease('draft');
    $startingRevision = (int) $release->getRevisionId();

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'sections_wrapper[items][existing_0][ai_assistant][profile]' => 'public_product',
      'sections_wrapper[items][existing_0][ai_assistant][instructions]' => 'Use simpler language.',
    ], 'Generate suggestion');
    $this->assertSession()->pageTextContains('Current wording');
    $this->assertSession()->pageTextContains('Suggested wording');
    $this->assertSession()->buttonExists('Use suggestion');
    $this->assertSession()->buttonExists('Try again');
    $this->assertSession()->buttonExists('Dismiss suggestion');
    $this->assertSession()->pageTextNotContains('Restore original from revision history');
    $this->submitForm([], 'Dismiss suggestion');
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $storage->resetCache([(int) $release->id()]);
    self::assertSame($startingRevision, (int) $storage->load($release->id())->getRevisionId());
    $this->assertSession()->pageTextContains('The release was not changed.');
  }

  /**
   * Tests archived and missing-evidence states are explicit and safe.
   */
  public function testArchivedAndMissingEvidenceStates(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->save();

    $archived = $this->createRelease('archived');
    $this->drupalGet($archived->toUrl('edit-form'));
    $this->assertSession()->pageTextContains('Archived releases cannot be rewritten.');
    $this->assertSession()->buttonNotExists('Generate release suggestions');

    $missing = $this->createRelease('draft', 'missing');
    $this->drupalGet($missing->toUrl('edit-form'));
    $this->assertSession()->pageTextContains('no current notes eligible for AI rewriting');
    $this->assertSession()->buttonNotExists('Generate release suggestions');
  }

  /**
   * Tests a concurrent release revision invalidates staged suggestions.
   */
  public function testConcurrentEditRejectsStaleSuggestion(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'use changelogify ai',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->config('changelogify_ai.settings')
      ->set('consent_external_processing', TRUE)
      ->save();
    $release = $this->createRelease('draft');

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'ai_workspace[profile]' => 'concise',
      'ai_workspace[items][item-1]' => 'item-1',
      'ai_workspace[items][item-2]' => 'item-2',
    ], 'Generate release suggestions');

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $storage->resetCache([(int) $release->id()]);
    $concurrent = $storage->load($release->id());
    $concurrent->setNewRevision(TRUE);
    $concurrent->setRevisionLogMessage('Concurrent editorial update.');
    $concurrent->setTitle('Concurrently updated release');
    $concurrent->save();
    $concurrentRevision = (int) $concurrent->getRevisionId();

    $this->submitForm([], 'Use all suggestions');
    $this->assertSession()->pageTextContains('release may have changed');
    $storage->resetCache([(int) $release->id()]);
    $unchanged = $storage->load($release->id());
    self::assertSame($concurrentRevision, (int) $unchanged->getRevisionId());
    self::assertSame('Concurrently updated release', $unchanged->getTitle());
  }

  /**
   * Creates a release with two stable, privacy-bounded evidence references.
   */
  private function createRelease(string $state, string $evidenceStatus = 'available'): object {
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $release = $storage->create([
      'title' => ucfirst($state) . ' AI editorial release',
      'release_date' => 1_700_000_000,
      'status' => $state === 'published',
      'editorial_state' => $state,
    ]);
    $release->setSections([
      'changed' => [
        ['id' => 'item-1', 'text' => 'First current note', 'event_ids' => [101]],
        ['id' => 'item-2', 'text' => 'Second current note', 'event_ids' => [102]],
      ],
    ])->setProvenance([
      'version' => 1,
      'items' => [
        'item-1' => [
          'event_ids' => [101],
          'event_count' => 1,
          'evidence_status' => $evidenceStatus,
          'events' => [['event_id' => 101, 'evidence_status' => $evidenceStatus]],
        ],
        'item-2' => [
          'event_ids' => [102],
          'event_count' => 1,
          'evidence_status' => $evidenceStatus,
          'events' => [['event_id' => 102, 'evidence_status' => $evidenceStatus]],
        ],
      ],
    ])->save();
    return $release;
  }

}
