<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\changelogify\EventManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
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

    $this->drupalGet('/changelog/' . $published->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Visible public change');

    $this->drupalGet('/changelog/' . $draft->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Confidential draft change');
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
          'changelogify_release',
          'changelogify_release__status_date',
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
    $this->assertSession()->elementExists(
      'css',
      'input[name="sections_wrapper[items][existing_0][text]"]',
    );
    $this->assertSession()->elementNotExists(
      'css',
      'textarea[name^="sections_wrapper"]',
    );

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
    $release = \Drupal::entityTypeManager()
      ->getStorage('changelogify_release')
      ->load($release->id());
    $sections = $release->getSections();
    self::assertSame($itemId, $sections['security'][0]['id']);
    self::assertSame($itemEvidence, $sections['security'][0]['event_ids']);
    self::assertSame('Edited selected change', $sections['security'][0]['text']);
    self::assertSame([], $sections['added'][0]['event_ids']);
    self::assertSame('security', $release->getProvenance()['items'][$itemId]['section']);
  }

  /**
   * Tests stale evidence recovery and explicit empty-draft confirmation.
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
    $this->submitForm([], 'Create draft release');
    $this->assertSession()->pageTextContains('Confirm that you want to create an empty draft.');
    $this->submitForm(['confirm_empty' => TRUE], 'Create draft release');
    $this->assertSession()->pageTextContains('has been created');
    $releaseCount = \Drupal::entityQuery('changelogify_release')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    self::assertSame(1, (int) $releaseCount);
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
    $this->assertSession()->pageTextContains('overlaps published release');
    $this->assertSession()->pageTextContains('reuses evidence from');
    $changeSetId = 'changeset-' . substr(hash('sha256', 'event:' . $event->id()), 0, 24);
    $this->submitForm([
      'change_sets[' . $changeSetId . '][include]' => TRUE,
      'change_sets[' . $changeSetId . '][section]' => 'changed',
    ], 'Create draft release');
    $this->assertSession()->pageTextContains('Confirm the intentional reuse of evidence');
    $this->submitForm([
      'change_sets[' . $changeSetId . '][include]' => TRUE,
      'change_sets[' . $changeSetId . '][section]' => 'changed',
      'confirm_reuse' => TRUE,
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
    $this->assertSession()->pageTextContains('A coverage gap exists');
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
    $this->assertSession()->pageTextContains('Newly discovered entity types and bundles are disabled');
    $this->submitForm([
      'content_capture[node][bundles][page]' => FALSE,
    ], 'Save configuration');

    Node::create([
      'type' => 'page',
      'title' => 'Excluded through settings',
      'status' => TRUE,
    ])->save();
    self::assertSame(0, $this->eventCount('node_created'));
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
