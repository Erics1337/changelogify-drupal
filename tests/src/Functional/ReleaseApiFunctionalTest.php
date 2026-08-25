<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the versioned, read-only public release API.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseApiFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['changelogify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests schema, access, pagination, privacy, and conditional requests.
   */
  public function testReleaseApiV1(): void {
    $this->config('system.performance')->set('cache.page.max_age', 3600)->save();
    $draft = $this->release('Private draft', FALSE);
    $first = $this->release('Public one', TRUE);
    $second = $this->release('Public two', TRUE);
    $third = $this->release('Public three', TRUE);

    $this->drupalGet('/changelog/api/v1/releases');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/changelog/api/v1/releases/' . $draft->getSlug());
    $this->assertSession()->statusCodeEquals(403);

    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertNotNull($anonymous);
    $anonymous->grantPermission('view changelogify releases')->save();

    $this->drupalGet('/changelog/api/v1/releases', ['query' => ['limit' => 2, 'offset' => 0]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    $etag = $this->getSession()->getResponseHeader('ETag');
    self::assertNotEmpty($etag);
    self::assertNotEmpty($this->getSession()->getResponseHeader('Last-Modified'));
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'changelogify_release_list');
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'changelogify_release:' . $third->id());
    $page = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('changelogify.release-list.v1', $page['schema']);
    self::assertSame(['limit' => 2, 'offset' => 0, 'has_more' => TRUE], $page['pagination']);
    self::assertSame(['Public three', 'Public two'], array_column($page['releases'], 'title'));
    self::assertSame([
      'uuid',
      'slug',
      'url',
      'title',
      'version',
      'language',
      'release_date',
      'coverage',
      'sections',
    ], array_keys($page['releases'][0]));
    self::assertSame(['text' => 'Public three notes'], $page['releases'][0]['sections']['changed']['items'][0]);
    self::assertStringStartsWith('http://', $page['releases'][0]['url']);
    foreach (['id', 'event_ids', 'provenance', 'actor', 'editorial_state', 'ai'] as $privateKey) {
      self::assertStringNotContainsString('"' . $privateKey . '"', $this->getSession()->getPage()->getContent());
    }
    self::assertStringNotContainsString('Private draft', $this->getSession()->getPage()->getContent());

    $this->drupalGet('/changelog/api/v1/releases', ['query' => ['limit' => 2, 'offset' => 2]]);
    $pageTwoEtag = $this->getSession()->getResponseHeader('ETag');
    self::assertNotEmpty($pageTwoEtag);
    self::assertNotSame($etag, $pageTwoEtag);
    $pageTwo = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(['Public one'], array_column($pageTwo['releases'], 'title'));
    self::assertFalse($pageTwo['pagination']['has_more']);

    $this->drupalGet('/changelog/api/v1/releases', ['query' => ['limit' => 999, 'offset' => -10]]);
    $bounded = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(20, $bounded['pagination']['limit']);
    self::assertSame(0, $bounded['pagination']['offset']);

    $detailPath = '/changelog/api/v1/releases/' . $first->getSlug();
    $this->drupalGet($detailPath);
    $detail = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('changelogify.release.v1', $detail['schema']);
    self::assertSame($first->uuid(), $detail['release']['uuid']);
    self::assertSame('en', $detail['release']['language']);
    self::assertNull($detail['release']['coverage']['start']);

    $this->drupalGet('/changelog/api/v1/releases/' . $draft->getSlug());
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/changelog/api/v1/releases/does-not-exist');
    $this->assertSession()->statusCodeEquals(404);

    $third->setTitle('Public three updated')->save();
    $this->drupalGet('/changelog/api/v1/releases', ['query' => ['limit' => 2, 'offset' => 0]]);
    $updated = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('Public three updated', $updated['releases'][0]['title']);

    $authenticated = $this->drupalCreateUser([]);
    $this->drupalLogin($authenticated);
    $this->drupalGet('/changelog/api/v1/releases');
    $this->assertSession()->statusCodeEquals(403);
    $privileged = $this->drupalCreateUser(['manage changelogify releases']);
    $this->drupalLogin($privileged);
    $this->drupalGet('/changelog/api/v1/releases');
    $this->assertSession()->statusCodeEquals(403);
    $viewer = $this->drupalCreateUser(['view changelogify releases']);
    $this->drupalLogin($viewer);
    $this->drupalGet('/changelog/api/v1/releases/' . $second->getSlug());
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Creates one public or private release.
   */
  private function release(string $title, bool $published): object {
    $release = \Drupal::entityTypeManager()->getStorage('changelogify_release')->create([
      'title' => $title,
      'version' => $published ? '1.0.0' : NULL,
      'release_date' => 1_700_000_000,
      'status' => $published,
      'editorial_state' => $published ? 'published' : 'draft',
    ]);
    $release->setSections([
      'changed' => [[
        'id' => strtolower(str_replace(' ', '-', $title)),
        'text' => $title . ' notes',
        'event_ids' => [99],
      ],
      ],
    ])->save();
    return $release;
  }

}
