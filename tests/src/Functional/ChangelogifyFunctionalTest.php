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

/**
 * Tests the Changelogify admin interface.
 */
#[Group('changelogify')]
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
    $eventManager->logEvent([
      'timestamp' => strtotime('2025-01-15 12:00:00 UTC'),
      'event_type' => 'content_created',
      'source' => 'test',
      'message' => 'Included change',
      'section_hint' => 'added',
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
    ], 'Generate Release');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Draft release "January release" has been created.');
    $this->assertSession()->elementExists(
      'css',
      'textarea[name="sections_wrapper[section_added][items]"]',
    );
    $this->assertSession()->elementNotExists(
      'css',
      'textarea[name="sections[0][value]"]',
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
    self::assertCount(1, $sections['added']);
    self::assertSame('Included change', $sections['added'][0]['text']);
    self::assertSame('1.2.0-beta.1', $release->getVersion());
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
