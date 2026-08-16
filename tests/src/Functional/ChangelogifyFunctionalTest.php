<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the Changelogify admin interface.
 *
 * @group changelogify
 */
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

}
