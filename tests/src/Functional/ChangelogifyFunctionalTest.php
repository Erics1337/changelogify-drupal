<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\changelogify\EventSource\ContentCapturePolicyInterface;
use Drupal\changelogify\EventManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Changelogify admin interface.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
class ChangelogifyFunctionalTest extends BrowserTestBase {

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
  public function testDashboardAccess(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'manage changelogify releases',
      'access administration pages',
    ]);

    $this->drupalLogin($user);

    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = \Drupal::service(EventManagerInterface::class);
    $eventManager->logEvent([
      'event_type' => 'test_event',
      'source' => 'test',
      'message' => 'Visible event log entry',
      'section_hint' => 'other',
    ]);

    // Visit the dashboard.
    $this->drupalGet('/admin/config/development/changelogify');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Changelogify');
    $this->assertSession()->elementExists('css', '.changelogify-dashboard');
    $this->assertSession()->elementsCount('css', '.changelogify-stats .stat-card', 3);
    $this->assertSession()->responseContains('changelogify.dashboard.css');
    $this->assertSession()->linkExists('View captured events');
    $this->assertSession()->linkByHrefExists('/admin/content/changelogify/events');
    $this->assertSession()->elementExists(
      'css',
      '.changelogify-stats a.stat-card[href*="date_from"]',
    );

    // Visit the release list.
    $this->drupalGet('/admin/content/changelogify/releases');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Releases');

    // Visit the captured event log.
    $this->drupalGet('/admin/content/changelogify/events');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Visible event log entry');
  }

  /**
   * Tests event explorer filters, details, redaction, and access control.
   */
  public function testEventExplorer(): void {
    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = \Drupal::service(EventManagerInterface::class);
    $first = $eventManager->logEvent([
      'timestamp' => strtotime('2025-02-10 12:00:00 UTC'),
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => '<script>unsafe event</script>',
      'entity_type_id' => 'node',
      'entity_id' => 42,
      'bundle' => 'article',
      'section_hint' => 'changed',
      'correlation_id' => 'deployment-123',
      'metadata' => [
        'label' => '<b>Escaped label</b>',
        'api_token' => 'must-not-render',
      ],
    ]);
    $eventManager->logEvent([
      'timestamp' => strtotime('2025-02-10 12:01:00 UTC'),
      'event_type' => 'config_imported',
      'source' => 'config',
      'message' => 'Correlated configuration change',
      'section_hint' => 'changed',
      'correlation_id' => 'deployment-123',
    ]);
    $eventManager->logEvent([
      'timestamp' => strtotime('2025-02-11 12:00:00 UTC'),
      'event_type' => 'user_created',
      'source' => 'user',
      'message' => 'Filtered out event',
      'section_hint' => 'added',
    ]);

    $detailPath = '/admin/content/changelogify/events/' . $first->id();
    $this->drupalGet($detailPath);
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser([
      'administer changelogify',
      'access administration pages',
    ]);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/content/changelogify/events', [
      'query' => [
        'date_from' => '2025-02-10',
        'date_to' => '2025-02-10',
        'source' => 'content_entity',
        'event_type' => 'content_updated',
        'entity_type' => 'node',
        'bundle' => 'article',
        'section_hint' => 'changed',
        'correlation_id' => 'deployment-123',
        'release_inclusion' => 'unused',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('unsafe event');
    $this->assertSession()->pageTextNotContains('Filtered out event');
    $this->assertSession()->responseNotContains('<script>unsafe event</script>');

    $releaseStorage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $release = $releaseStorage->create([
      'title' => 'Explorer evidence release',
      'release_date' => strtotime('2025-02-12 12:00:00 UTC'),
      'status' => FALSE,
    ]);
    $release->setSections([
      'changed' => [
        [
          'id' => 'explorer-evidence-item',
          'text' => 'Evidence-backed item',
          'event_ids' => [(int) $first->id()],
        ],
      ],
    ])->save();
    $this->drupalGet('/admin/content/changelogify/events', [
      'query' => ['release_inclusion' => 'included'],
    ]);
    $this->assertSession()->pageTextContains('unsafe event');
    $this->assertSession()->pageTextNotContains('Filtered out event');

    $this->drupalGet($detailPath);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Normalized metadata');
    $this->assertSession()->pageTextContains('[redacted]');
    $this->assertSession()->pageTextNotContains('must-not-render');
    $this->assertSession()->pageTextContains('Correlated configuration change');
    $this->assertSession()->responseNotContains('<b>Escaped label</b>');

    $this->drupalGet('/admin/content/changelogify/events', [
      'query' => [
        'date_from' => '2025-02-12',
        'date_to' => '2025-02-10',
      ],
    ]);
    $this->assertSession()->pageTextContains('The end date must not be before the start date.');

    for ($index = 0; $index < 51; $index++) {
      $eventManager->logEvent([
        'timestamp' => strtotime('2025-03-01 12:00:00 UTC') + $index,
        'event_type' => 'pagination_test',
        'source' => 'test',
        'message' => 'Paginated event ' . $index,
        'section_hint' => 'other',
      ]);
    }
    $this->drupalGet('/admin/content/changelogify/events', [
      'query' => ['event_type' => 'pagination_test'],
    ]);
    $this->assertSession()->elementExists('css', 'nav.pager');
    $this->assertSession()->pageTextContains('Paginated event 50');
    $this->assertSession()->pageTextNotContains('Paginated event 0');
  }

  /**
   * Tests attribute and legacy hook implementations do not both execute.
   */
  public function testHooksExecuteOnlyOnce(): void {
    $this->config('changelogify.settings')
      ->set('track_users', TRUE)
      ->save();

    $this->drupalCreateUser();

    $count = \Drupal::entityQuery('changelogify_event')
      ->accessCheck(FALSE)
      ->condition('event_type', 'user_created')
      ->count()
      ->execute();

    self::assertSame(1, (int) $count);
  }

  /**
   * Tests public listings and detail pages never expose draft releases.
   */
  public function testDraftReleasesArePrivate(): void {
    $anonymousRole = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertNotNull($anonymousRole);
    $anonymousRole->grantPermission('view changelogify releases')->save();

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $published */
    $published = $storage->create([
      'title' => 'Public release',
      'release_date' => 1_700_000_000,
      'status' => TRUE,
    ]);
    $published->setSections([
      'added' => [[
        'id' => 'public-item',
        'text' => 'Visible public change',
        'event_ids' => [],
      ],
      ],
    ]);
    $published->save();

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $draft */
    $draft = $storage->create([
      'title' => 'Private draft release',
      'release_date' => 1_700_000_001,
      'status' => FALSE,
    ]);
    $draft->setSections([
      'added' => [[
        'id' => 'draft-item',
        'text' => 'Confidential draft change',
        'event_ids' => [],
      ],
      ],
    ]);
    $draft->save();

    $this->drupalGet('/changelog');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Public release');
    $this->assertSession()->pageTextNotContains('Private draft release');
    $this->assertSession()->responseContains('changelogify.public.css');
    $this->assertSession()->elementExists('css', '.changelogify-release-list[aria-live="polite"]');
    $this->assertSession()->elementExists('css', 'article h2 a[rel="bookmark"]');
    $this->assertSession()->elementExists('css', 'time[datetime]');
    $this->assertSession()->elementAttributeContains('css', 'link[rel="canonical"]', 'href', '/changelog');

    $this->drupalGet('/changelog/' . $published->getSlug());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Visible public change');
    $this->assertSession()->elementExists('css', '.release-section h2');
    $this->assertSession()->elementExists('css', '.release-section ul li');
    $this->assertSession()->elementAttributeContains('css', 'link[rel="canonical"]', 'href', '/changelog/' . $published->getSlug());
    $this->assertSession()->responseNotContains('public-item');

    // Prime page caches, then verify every lifecycle change invalidates them.
    $published->setTitle('Updated public release');
    $published->setSections([
      'fixed' => [[
        'id' => 'private-identity',
        'text' => 'Freshly rendered public change',
        'event_ids' => ['private-evidence'],
      ],
      ],
    ])->save();
    $this->drupalGet('/changelog');
    $this->assertSession()->pageTextContains('Updated public release');
    $this->drupalGet('/changelog/' . $published->getSlug());
    $this->assertSession()->pageTextContains('Freshly rendered public change');
    $this->assertSession()->responseNotContains('private-identity');
    $this->assertSession()->responseNotContains('private-evidence');

    $published->setEditorialState('draft')->save();
    $this->drupalGet('/changelog');
    $this->assertSession()->pageTextNotContains('Updated public release');
    $this->drupalGet('/changelog/' . $published->getSlug());
    $this->assertSession()->statusCodeEquals(404);

    $published->setEditorialState('published')->save();
    $this->drupalGet('/changelog');
    $this->assertSession()->pageTextContains('Updated public release');
    $this->drupalGet('/changelog/' . $published->getSlug());
    $this->assertSession()->statusCodeEquals(200);

    $published->delete();
    $this->drupalGet('/changelog');
    $this->assertSession()->pageTextNotContains('Updated public release');
    $this->drupalGet('/changelog/' . $published->getSlug());
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('/changelog/' . $draft->getSlug());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Confidential draft change');
    $response = $this->getHttpClient()->get($this->buildUrl('/changelog/' . $draft->id()), [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ]);
    self::assertSame(404, $response->getStatusCode());
  }

  /**
   * Tests permission-controlled states, revisions, archive, and restoration.
   */
  public function testReleaseEditorialWorkflow(): void {
    $anonymousRole = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertNotNull($anonymousRole);
    $anonymousRole->grantPermission('view changelogify releases')->save();
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => 'Workflow release',
      'release_date' => 1_700_000_000,
      'status' => FALSE,
    ]);
    $release->save();

    $manager = $this->drupalCreateUser(['manage changelogify releases']);
    $this->drupalLogin($manager);
    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm(['editorial_state' => 'published'], 'Save');
    $this->assertSession()->pageTextContains('do not have permission');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame('draft', $release->getEditorialState());

    $editor = $this->drupalCreateUser([
      'manage changelogify releases',
      'submit changelogify releases for review',
      'publish changelogify releases',
      'archive changelogify releases',
      'view changelogify release revisions',
      'revert changelogify release revisions',
    ]);
    $this->drupalLogin($editor);
    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'editorial_state' => 'review',
      'revision_log_message[0][value]' => 'Ready for stakeholder review.',
    ], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame('review', $release->getEditorialState());
    self::assertFalse($release->isPublished());
    $this->drupalLogout();
    $this->drupalGet('/changelog/' . $release->getSlug());
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalLogin($editor);

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'editorial_state' => 'published',
      'revision_log_message[0][value]' => 'Approved for publication.',
    ], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame('published', $release->getEditorialState());
    self::assertTrue($release->isPublished());
    $publishedRevisionId = (int) $release->getRevisionId();
    $revisionIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $release->id())
      ->allRevisions()
      ->execute();
    self::assertGreaterThanOrEqual(3, count($revisionIds));
    $this->drupalLogout();
    $this->drupalGet('/changelog/' . $release->getSlug());
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('/admin/content/changelogify/releases/' . $release->id() . '/view');
    $this->assertSession()->statusCodeEquals(403);
    $revisionViewer = $this->drupalCreateUser(['view changelogify release revisions']);
    $this->drupalLogin($revisionViewer);
    $this->drupalGet($release->toUrl('version-history'));
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($editor);

    $this->drupalGet($release->toUrl('version-history'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Approved for publication.');

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm(['editorial_state' => 'archived'], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame('archived', $release->getEditorialState());
    self::assertFalse($release->isPublished());
    $this->drupalLogout();
    $this->drupalGet('/changelog/' . $release->getSlug());
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalLogin($editor);

    $revertUrl = '/admin/content/changelogify/releases/' . $release->id()
      . '/revisions/' . $publishedRevisionId . '/revert';
    $this->drupalGet($revertUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Revert');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame('published', $release->getEditorialState());
    self::assertTrue($release->isPublished());
    self::assertGreaterThan($publishedRevisionId, (int) $release->getRevisionId());
    $this->drupalLogout();
    $this->drupalGet('/changelog/' . $release->getSlug());
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests schedule controls, permission enforcement, timezone, and canceling.
   */
  public function testScheduledPublicationForm(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $release = $storage->create([
      'title' => 'Scheduled workflow release',
      'release_date' => 1_700_000_000,
      'editorial_state' => 'review',
    ]);
    $release->save();

    $manager = $this->drupalCreateUser(['manage changelogify releases']);
    $this->drupalLogin($manager);
    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->fieldNotExists('publish_at[date]');

    $publisher = $this->drupalCreateUser([
      'manage changelogify releases',
      'submit changelogify releases for review',
      'publish changelogify releases',
      'view changelogify release revisions',
    ]);
    $publisher->set('timezone', 'America/Los_Angeles')->save();
    $this->drupalLogin($publisher);
    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->fieldExists('publish_at[date]');
    $this->submitForm([
      'editorial_state' => 'review',
      'publish_at[date]' => '2030-01-02',
      'publish_at[time]' => '10:30:00',
    ], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertGreaterThan(0, $release->getScheduledPublicationTime());
    self::assertNotNull($release->getScheduledRevisionId());
    $firstTimestamp = $release->getScheduledPublicationTime();

    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->pageTextContains(
      \Drupal::service('date.formatter')->format($firstTimestamp, 'long'),
    );
    $this->submitForm([
      'editorial_state' => 'review',
      'publish_at[date]' => '2030-02-03',
      'publish_at[time]' => '11:45:00',
    ], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertNotSame($firstTimestamp, $release->getScheduledPublicationTime());

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'editorial_state' => 'review',
      'publish_at[date]' => '2030-02-03',
      'publish_at[time]' => '11:45:00',
      'cancel_schedule' => TRUE,
    ], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame(0, $release->getScheduledPublicationTime());
    self::assertNull($release->getScheduledRevisionId());
    $this->assertSession()->pageTextContains('Scheduled publication has been canceled.');
    $this->drupalGet($release->toUrl('version-history'));
    $this->assertSession()->pageTextContains('Scheduled publication approved by an editor.');
    $this->assertSession()->pageTextContains('Scheduled publication rescheduled by an editor.');
    $this->assertSession()->pageTextContains('Scheduled publication canceled by an editor.');

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm([
      'editorial_state' => 'review',
      'publish_at[date]' => '2030-03-04',
      'publish_at[time]' => '12:15:00',
    ], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertGreaterThan(0, $release->getScheduledPublicationTime());

    $this->drupalGet($release->toUrl('edit-form'));
    $this->submitForm(['editorial_state' => 'draft'], 'Save');
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertSame('draft', $release->getEditorialState());
    self::assertSame(0, $release->getScheduledPublicationTime());
    self::assertNull($release->getScheduledRevisionId());
  }

  /**
   * Tests release provenance requires the release-management permission.
   */
  public function testProvenanceAccess(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => 'Evidence release',
      'release_date' => 1_700_000_000,
      'status' => TRUE,
    ]);
    $release->setSections([
      'other' => [
        [
          'id' => 'safe-item',
          'text' => 'Evidence item',
          'event_ids' => [],
        ],
      ],
    ]);
    $release->setProvenance([
      'version' => 1,
      'items' => [
        'safe-item' => [
          'event_ids' => [],
          'evidence_status' => 'removed',
          'events' => [],
        ],
      ],
    ])->save();
    $path = '/admin/content/changelogify/releases/' . $release->id() . '/provenance';

    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    $viewer = $this->drupalCreateUser(['view changelogify releases']);
    $this->drupalLogin($viewer);
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    $manager = $this->drupalCreateUser(['manage changelogify releases']);
    $this->drupalLogin($manager);
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('removed');
    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Based on 0 tracked change(s) · Evidence details removed');
    $this->assertSession()->pageTextContains('Technical details');
    $this->assertSession()->elementNotExists(
      'css',
      'a[href^="/admin/content/changelogify/events/"]',
    );
  }

  /**
   * Tests fresh installations create the query indexes.
   */
  public function testQueryIndexesAreInstalled(): void {
    $schema = \Drupal::database()->schema();

    self::assertTrue($schema->indexExists(
          'changelogify_event',
          'changelogify_event__timestamp',
      ));
    self::assertTrue($schema->indexExists(
          'changelogify_event',
          'changelogify_event__event_type_timestamp',
      ));
    self::assertTrue($schema->indexExists(
      'changelogify_release_field_data',
      'changelogify_release__status',
    ));
    self::assertTrue($schema->indexExists(
      'changelogify_release_field_data',
      'changelogify_release__release_date',
    ));
    self::assertTrue($schema->indexExists(
      'changelogify_release_field_data',
      'changelogify_release__scheduled_at',
    ));
  }

  /**
   * Tests a custom date range generates a release with only matching events.
   */
  public function testCustomDateRangeGeneration(): void {
    $this->config('system.date')
      ->set('timezone.default', 'UTC')
      ->save();

    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'administer changelogify',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = \Drupal::service(EventManagerInterface::class);
    $firstIncluded = $eventManager->logEvent([
      'timestamp' => strtotime('2025-01-15 12:00:00 UTC'),
      'event_type' => 'content_created',
      'source' => 'test',
      'message' => 'Included change',
      'section_hint' => 'added',
    ]);
    $secondIncluded = $eventManager->logEvent([
      'timestamp' => strtotime('2025-01-15 13:00:00 UTC'),
      'event_type' => 'content_updated',
      'source' => 'test',
      'message' => 'Selected change',
      'section_hint' => 'changed',
    ]);
    $eventManager->logEvent([
      'timestamp' => strtotime('2025-01-16 00:00:00 UTC'),
      'event_type' => 'content_created',
      'source' => 'test',
      'message' => 'Excluded change',
      'section_hint' => 'added',
    ]);

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'mode' => 'custom',
      'start_date[date]' => '2025-01-15',
      'end_date[date]' => '2025-01-15',
      'title' => 'January release',
      'version' => '1.2.0-beta.1',
    ], 'Preview changes');

    $ids = \Drupal::entityQuery('changelogify_release')
      ->accessCheck(FALSE)
      ->condition('title', 'January release')
      ->execute();
    self::assertCount(0, $ids, 'Preview does not persist a release.');
    $this->assertSession()->pageTextContains('Included change');
    $this->assertSession()->pageTextContains('Selected change');
    $this->assertSession()->pageTextNotContains('Excluded change');
    $firstId = 'changeset-' . substr(hash('sha256', 'event:' . $firstIncluded->id()), 0, 24);
    $secondId = 'changeset-' . substr(hash('sha256', 'event:' . $secondIncluded->id()), 0, 24);
    $firstIncludeName = 'change_sets[' . $firstId . '][include]';
    $secondIncludeName = 'change_sets[' . $secondId . '][include]';
    $secondSectionName = 'change_sets[' . $secondId . '][section]';
    $this->submitForm([
      $firstIncludeName => FALSE,
      $secondIncludeName => TRUE,
      $secondSectionName => 'fixed',
    ], 'Create draft release');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Draft release "January release" has been created.');
    $this->assertSession()->responseContains('changelogify.editor.js');
    $this->assertSession()->pageTextContains('Based on 1 tracked change(s) · Evidence available');
    $this->assertSession()->elementExists(
      'css',
      'a[href*="/admin/content/changelogify/events/"]',
    );
    $this->assertSession()->elementExists(
      'css',
      'textarea[name="sections_wrapper[items][existing_0][text]"]',
    );
    $this->assertSession()->elementNotExists(
      'css',
      'input[name="sections_wrapper[items][existing_0][text]"]',
    );
    $this->assertSession()->pageTextNotContains('New manual item');
    $this->assertSession()->elementNotExists('css', '[name="sections_wrapper[items][manual_0][text]"]');
    $this->submitForm([], 'Add manual note');
    $this->assertSession()->elementExists('css', 'textarea[name="sections_wrapper[items][manual_0][text]"]');

    $ids = \Drupal::entityQuery('changelogify_release')
      ->accessCheck(FALSE)
      ->condition('title', 'January release')
      ->execute();
    self::assertCount(1, $ids);

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = \Drupal::entityTypeManager()
      ->getStorage('changelogify_release')
      ->load(reset($ids));
    $sections = $release->getSections();
    self::assertCount(0, $sections['added']);
    self::assertCount(1, $sections['fixed']);
    self::assertSame('Selected change', $sections['fixed'][0]['text']);
    self::assertSame('1.2.0-beta.1', $release->getVersion());

    $itemId = $sections['fixed'][0]['id'];
    $itemEvidence = $sections['fixed'][0]['event_ids'];
    $this->submitForm([
      'sections_wrapper[items][existing_0][text]' => 'Edited selected change',
      'sections_wrapper[items][existing_0][section]' => 'security',
      'sections_wrapper[items][existing_0][order]' => 0,
      'sections_wrapper[items][manual_0][text]' => 'Editorial context',
      'sections_wrapper[items][manual_0][section]' => 'added',
      'sections_wrapper[items][manual_0][order]' => 0,
    ], 'Save');
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $storage->resetCache([$release->id()]);
    $release = $storage->load($release->id());
    $sections = $release->getSections();
    self::assertSame($itemId, $sections['security'][0]['id']);
    self::assertSame($itemEvidence, $sections['security'][0]['event_ids']);
    self::assertSame('Edited selected change', $sections['security'][0]['text']);
    self::assertSame([], $sections['added'][0]['event_ids']);
    self::assertSame('security', $release->getProvenance()['items'][$itemId]['section']);
    $this->drupalGet($release->toUrl('edit-form'));
    $this->assertSession()->pageTextContains('Manual note — no tracked change is attached.');
  }

  /**
   * Tests stale evidence recovery and prevention of empty drafts.
   */
  public function testReleasePreviewRevalidatesEvidence(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = \Drupal::service(EventManagerInterface::class);
    $event = $eventManager->logEvent([
      'event_type' => 'content_updated',
      'source' => 'test',
      'message' => 'Evidence removed after preview',
      'section_hint' => 'changed',
    ]);

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm(['mode' => 'since_last'], 'Preview changes');
    $this->assertSession()->pageTextContains('Evidence removed after preview');
    $eventId = 'changeset-' . substr(hash('sha256', 'event:' . $event->id()), 0, 24);
    \Drupal::entityTypeManager()->getStorage('changelogify_event')->delete([$event]);
    $this->submitForm([
      'change_sets[' . $eventId . '][include]' => TRUE,
      'change_sets[' . $eventId . '][section]' => 'changed',
    ], 'Create draft release');
    $this->assertSession()->pageTextContains('Preview the release window again and retry.');
    $releaseCount = \Drupal::entityQuery('changelogify_release')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    self::assertSame(0, (int) $releaseCount);

    $this->submitForm([
      'mode' => 'custom',
      'start_date[date]' => '2030-01-01',
      'end_date[date]' => '2030-01-01',
    ], 'Preview changes');
    $this->assertSession()->pageTextContains('No change sets were found');
    $this->assertSession()->buttonExists('Create draft release');
    $button = $this->getSession()->getPage()->findButton('Create draft release');
    self::assertNotNull($button);
    self::assertTrue($button->hasAttribute('disabled'));
    $this->assertSession()->fieldNotExists('confirm_empty');
    $releaseCount = \Drupal::entityQuery('changelogify_release')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    self::assertSame(0, (int) $releaseCount);
  }

  /**
   * Tests overlap, gap, reused-evidence, and timestamp-boundary warnings.
   */
  public function testReleaseCoverageWarnings(): void {
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = \Drupal::service(EventManagerInterface::class);
    $boundary = strtotime('2025-04-01 23:59:59 UTC');
    $event = $eventManager->logEvent([
      'timestamp' => $boundary,
      'event_type' => 'content_updated',
      'source' => 'test',
      'message' => 'Boundary evidence',
      'section_hint' => 'changed',
    ]);
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $existing = $storage->create([
      'title' => 'Backdated published release',
      'release_date' => $boundary,
      'date_start' => strtotime('2025-04-01 00:00:00 UTC'),
      'date_end' => $boundary,
      'status' => TRUE,
    ]);
    $existing->setSections([
      'changed' => [
        [
          'id' => 'boundary-item',
          'text' => 'Already released evidence',
          'event_ids' => [(int) $event->id()],
        ],
      ],
    ])->save();

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm(['mode' => 'since_last'], 'Preview changes');
    $this->assertSession()->pageTextContains('Boundary evidence');
    $this->assertSession()->pageTextContains('1 overlapping release(s)');
    $this->assertSession()->pageTextContains('1 previously used change(s)');
    $this->assertSession()->pageTextContains('Already included in another release');
    $this->assertSession()->pageTextContains('Already used in: Backdated published release');
    $this->assertSession()->pageTextNotContains('changeset-');
    $this->assertSession()->fieldNotExists('confirm_reuse');
    $changeSetId = 'changeset-' . substr(hash('sha256', 'event:' . $event->id()), 0, 24);
    $this->assertSession()->checkboxNotChecked('change_sets[' . $changeSetId . '][include]');
    $this->submitForm([
      'change_sets[' . $changeSetId . '][include]' => TRUE,
      'change_sets[' . $changeSetId . '][section]' => 'changed',
    ], 'Create draft release');
    $this->assertSession()->pageTextContains('has been created');
    $createdIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $created */
    $created = $storage->load(reset($createdIds));
    $reuse = $created->getProvenance()['items'][$changeSetId]['evidence_reuse'];
    self::assertSame([(int) $existing->id()], $reuse['release_ids']);
    self::assertSame((int) $user->id(), $reuse['confirmed_by']);

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm([
      'mode' => 'custom',
      'start_date[date]' => '2025-04-03',
      'end_date[date]' => '2025-04-03',
    ], 'Preview changes');
    $this->assertSession()->pageTextContains('1 coverage gap');
  }

  /**
   * Tests unbounded history and generated release-option guidance.
   */
  public function testUnboundedReleaseBoundaryAndOptionDefaults(): void {
    $this->config('system.date')->set('timezone.default', 'America/Denver')->save();
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $legacy = $storage->create([
      'title' => 'Legacy initial release',
      'release_date' => strtotime('2025-01-02 12:00:00 UTC'),
      'date_start' => 0,
      'date_end' => strtotime('2025-01-02 12:00:00 UTC'),
      'status' => TRUE,
    ]);
    $legacy->setSections([])->save();

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->submitForm([
      'mode' => 'custom',
      'start_date[date]' => '2025-01-01',
      'end_date[date]' => '2025-01-03',
    ], 'Preview changes');

    $this->assertSession()->pageTextContains('Beginning of recorded history');
    $this->assertSession()->pageTextNotContains('1969');
    $this->assertSession()->pageTextNotContains('1970');
    $this->assertSession()->pageTextContains('Leave blank to use “Release - January 2025”.');
    $this->assertSession()->pageTextContains('Leave blank to use a date-based release label instead of a version badge.');

    $this->drupalGet($legacy->toUrl('edit-form'));
    $this->assertSession()->pageTextContains('Beginning of recorded history');
    $this->assertSession()->pageTextNotContains('1969');
    $this->assertSession()->pageTextNotContains('1970');
  }

  /**
   * Tests administrative release and revision pages render useful content.
   */
  public function testAdministrativeReleaseView(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'view changelogify release revisions',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => 'Administrative release',
      'version' => '1.7.0',
      'release_date' => strtotime('2025-05-02 12:00:00 UTC'),
      'date_start' => strtotime('2025-05-01 00:00:00 UTC'),
      'date_end' => strtotime('2025-05-02 12:00:00 UTC'),
      'status' => TRUE,
      'editorial_state' => 'published',
      'slug' => 'administrative-release',
    ]);
    $release->setSections([
      'added' => [[
        'id' => 'admin-item',
        'text' => 'Original administrative release content.',
        'event_ids' => [],
      ],
      ],
    ])->save();
    $originalRevisionId = (int) $release->getRevisionId();

    $this->drupalGet($release->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Original administrative release content.');
    $this->assertSession()->pageTextContains('Release details');
    $this->assertSession()->pageTextContains('Version');
    $this->assertSession()->pageTextContains('1.7.0');
    $this->assertSession()->linkExists('Edit release');
    $this->assertSession()->linkExists('Unpublish or archive');
    $this->assertSession()->linkExists('View public release');
    $this->assertSession()->linkExists('Revisions');
    $this->assertSession()->linkExists('Source evidence');

    $release->setNewRevision(TRUE);
    $release->setSections([
      'changed' => [[
        'id' => 'admin-item',
        'text' => 'Revised administrative release content.',
        'event_ids' => [],
      ],
      ],
    ])->save();
    $revisionUrl = Url::fromRoute('entity.changelogify_release.revision', [
      'changelogify_release' => $release->id(),
      'changelogify_release_revision' => $originalRevisionId,
    ]);
    $this->drupalGet($revisionUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Original administrative release content.');
    $this->assertSession()->pageTextNotContains('Revised administrative release content.');

    $empty = $storage->create(['title' => 'Empty administrative release']);
    $empty->save();
    $this->drupalGet($empty->toUrl('canonical'));
    $this->assertSession()->pageTextContains('does not contain any release items yet');
  }

  /**
   * Tests content tracking and unpublished-content privacy settings.
   */
  public function testContentTrackingSettings(): void {
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    $this->config('changelogify.settings')
      ->set('track_content', FALSE)
      ->save();
    Node::create([
      'type' => 'page',
      'title' => 'Tracking disabled',
      'status' => TRUE,
    ])->save();
    self::assertSame(0, $this->eventCount('node_created'));

    $this->config('changelogify.settings')
      ->set('track_content', TRUE)
      ->set('track_unpublished_content', FALSE)
      ->save();
    Node::create([
      'type' => 'page',
      'title' => 'Private draft',
      'status' => FALSE,
    ])->save();
    self::assertSame(0, $this->eventCount('node_created'));

    Node::create([
      'type' => 'page',
      'title' => 'Published page',
      'status' => TRUE,
    ])->save();
    self::assertSame(1, $this->eventCount('node_created'));

    $this->config('changelogify.settings')
      ->set('track_unpublished_content', TRUE)
      ->save();
    Node::create([
      'type' => 'page',
      'title' => 'Tracked private draft',
      'status' => FALSE,
    ])->save();
    self::assertSame(2, $this->eventCount('node_created'));
  }

  /**
   * Tests administrators can exclude a content bundle from capture.
   */
  public function testContentBundleCapturePolicyForm(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Explicit choices below always override');
    $this->assertSession()->pageTextContains('managed by automatic discovery');
    $this->assertSession()->fieldExists('content_capture[auto_track_new_safe_content]');
    $this->assertSession()->checkboxChecked('content_capture[auto_track_new_safe_content]');
    $this->assertSession()->buttonExists('Select all recommended');
    $this->assertSession()->buttonExists('Clear all capture selections');
    $this->assertSession()->fieldExists('content_capture[node][default_bundle_enabled]');
    $this->submitForm([], 'Clear Content bundles');
    $this->assertSession()->checkboxNotChecked('content_capture[auto_track_new_safe_content]');
    $this->assertSession()->checkboxNotChecked('content_capture[node][bundles][page]');
    $this->submitForm([], 'Select all recommended');
    $this->assertSession()->checkboxChecked('content_capture[auto_track_new_safe_content]');
    $this->assertSession()->checkboxChecked('content_capture[node][enabled]');
    $this->assertSession()->checkboxChecked('content_capture[node][default_bundle_enabled]');
    $this->assertSession()->checkboxChecked('content_capture[node][bundles][page]');
    $this->submitForm([
      'content_capture[node][bundles][page]' => FALSE,
      'content_capture[node][default_bundle_enabled]' => TRUE,
    ], 'Save configuration');

    Node::create([
      'type' => 'page',
      'title' => 'Excluded through settings',
      'status' => TRUE,
    ])->save();
    self::assertSame(0, $this->eventCount('node_created'));

    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    self::assertTrue(\Drupal::service(ContentCapturePolicyInterface::class)
      ->isBundleEnabled('node', 'news'));
  }

  /**
   * Tests retention deletes only events older than the cutoff.
   */
  public function testEventRetention(): void {
    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = \Drupal::service(EventManagerInterface::class);
    $now = \Drupal::time()->getCurrentTime();
    $old = $eventManager->logEvent([
      'timestamp' => $now - (91 * 86400),
      'event_type' => 'old_event',
      'source' => 'test',
      'message' => 'Expired event',
    ]);
    $current = $eventManager->logEvent([
      'timestamp' => $now - (89 * 86400),
      'event_type' => 'current_event',
      'source' => 'test',
      'message' => 'Retained event',
    ]);

    self::assertSame(1, $eventManager->purgeExpiredEvents(90));

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_event');
    self::assertNull($storage->load($old->id()));
    self::assertNotNull($storage->load($current->id()));
  }

  /**
   * Tests changing the public path rebuilds the dynamic routes.
   */
  public function testPublicPathSetting(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'view changelogify releases',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Privacy warning: stores labels and paths for unpublished or access-controlled content');
    $this->assertSession()->pageTextContains('Privacy warning: stores usernames and old/new role assignments');
    $this->submitForm([
      'changelog_path' => '/product-updates',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    self::assertSame(
      '/product-updates',
      $this->config('changelogify.settings')->get('changelog_path'),
    );

    $this->drupalGet('/product-updates');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/changelog');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests slug generation, collisions, history, and canonical redirects.
   */
  public function testPublicReleaseSlugs(): void {
    $anonymousRole = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertNotNull($anonymousRole);
    $anonymousRole->grantPermission('view changelogify releases')->save();
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'view changelogify releases',
    ]);
    $this->drupalLogin($user);
    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $first */
    $first = $storage->create([
      'title' => 'Summer Launch',
      'release_date' => 1_700_000_000,
      'status' => TRUE,
    ]);
    $first->save();
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $second */
    $second = $storage->create([
      'title' => 'Summer Launch',
      'release_date' => 1_700_000_001,
      'status' => TRUE,
    ]);
    $second->save();
    self::assertSame('summer-launch', $first->getSlug());
    self::assertSame('summer-launch-2', $second->getSlug());

    $first->setTitle('Renamed release')->save();
    self::assertSame('summer-launch', $first->getSlug(), 'Title edits do not change a stable slug.');
    $first->set('slug', 'Custom Launch')->save();
    self::assertSame('custom-launch', $first->getSlug());
    self::assertContains('summer-launch', $first->getSlugHistory());

    $this->drupalGet('/changelog/custom-launch');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Renamed release');
    $response = $this->getHttpClient()->get($this->buildUrl('/changelog/summer-launch'), [
      'allow_redirects' => FALSE,
    ]);
    self::assertSame(301, $response->getStatusCode());
    self::assertStringContainsString('/changelog/custom-launch', $response->getHeaderLine('Location'));
    $response = $this->getHttpClient()->get($this->buildUrl('/changelog/' . $first->id()), [
      'allow_redirects' => FALSE,
    ]);
    self::assertSame(301, $response->getStatusCode());
    self::assertStringContainsString('/changelog/custom-launch', $response->getHeaderLine('Location'));

    $this->config('changelogify.settings')->set('changelog_path', '/product-updates')->save();
    \Drupal::service('router.builder')->rebuild();
    $this->drupalGet('/product-updates/custom-launch');
    $this->assertSession()->statusCodeEquals(200);
    $response = $this->getHttpClient()->get($this->buildUrl('/product-updates/' . $first->id()), [
      'allow_redirects' => FALSE,
    ]);
    self::assertSame(301, $response->getStatusCode());
    self::assertStringContainsString('/product-updates/custom-launch', $response->getHeaderLine('Location'));
  }

  /**
   * Tests the public path cannot replace an existing Drupal route.
   */
  public function testPublicPathRejectsRouteCollisions(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'access administration pages',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/development/changelogify/settings');
    $this->submitForm([
      'changelog_path' => '/admin',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('That path is already used by another Drupal route.');
    self::assertSame(
      '/changelog',
      $this->config('changelogify.settings')->get('changelog_path'),
    );
  }

  /**
   * Counts stored events of a given type.
   */
  private function eventCount(string $eventType): int {
    return (int) \Drupal::entityQuery('changelogify_event')
      ->accessCheck(FALSE)
      ->condition('event_type', $eventType)
      ->count()
      ->execute();
  }

}
