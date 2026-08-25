<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests public RSS and Atom release feeds.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseFeedFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['changelogify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests formats, access filtering, identity, escaping, and discovery.
   */
  public function testPublicFeeds(): void {
    $viewer = $this->drupalCreateUser(['view changelogify releases']);
    $this->drupalLogin($viewer);

    foreach (['draft', 'review', 'archived'] as $state) {
      $this->release(ucfirst($state) . ' release', $state);
    }
    $published = $this->release(
      'Public & <safe> release',
      'published',
      'A safer <script>alert("no")</script> change & more.',
    );
    $uuid = $published->uuid();
    $initialSlug = $published->getSlug();

    $this->drupalGet('/changelog/feed.rss');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/rss+xml');
    $rss = $this->getSession()->getPage()->getContent();
    $rssXml = simplexml_load_string($rss);
    self::assertNotFalse($rssXml);
    self::assertSame('2.0', (string) $rssXml['version']);
    self::assertCount(1, $rssXml->channel->item);
    self::assertSame('Public & <safe> release', (string) $rssXml->channel->item[0]->title);
    self::assertSame('urn:uuid:' . $uuid, (string) $rssXml->channel->item[0]->guid);
    self::assertStringStartsWith('http://', (string) $rssXml->channel->item[0]->link);
    self::assertStringContainsString('&lt;script&gt;', (string) $rssXml->channel->item[0]->description);
    self::assertStringNotContainsString('<script>', $rss);
    foreach (['Draft release', 'Review release', 'Archived release'] as $privateTitle) {
      self::assertStringNotContainsString($privateTitle, $rss);
    }

    $this->drupalGet('/changelog/feed.atom');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/atom+xml');
    $atom = simplexml_load_string($this->getSession()->getPage()->getContent());
    self::assertNotFalse($atom);
    $atom->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
    $entries = $atom->xpath('/a:feed/a:entry');
    self::assertCount(1, $entries);
    self::assertSame('urn:uuid:' . $uuid, (string) $entries[0]->id);
    self::assertSame('Public & <safe> release', (string) $entries[0]->title);

    $this->drupalGet('/changelog');
    $this->assertSession()->elementExists('css', 'link[rel="alternate"][type="application/rss+xml"][href$="/changelog/feed.rss"]');
    $this->assertSession()->elementExists('css', 'link[rel="alternate"][type="application/atom+xml"][href$="/changelog/feed.atom"]');

    $published->setTitle('Renamed public release');
    $published->set('slug', 'renamed-public-release');
    $published->setSections([
      'changed' => [[
        'id' => 'edited-item',
        'text' => 'Updated feed content.',
        'event_ids' => [6],
      ],
      ],
    ]);
    $published->save();
    self::assertNotSame($initialSlug, $published->getSlug());

    $this->drupalGet('/changelog/feed.rss');
    $updatedRss = simplexml_load_string($this->getSession()->getPage()->getContent());
    self::assertNotFalse($updatedRss);
    self::assertSame('urn:uuid:' . $uuid, (string) $updatedRss->channel->item[0]->guid);
    self::assertSame('Renamed public release', (string) $updatedRss->channel->item[0]->title);
    self::assertStringContainsString('Updated feed content.', (string) $updatedRss->channel->item[0]->description);
    self::assertStringNotContainsString('event_ids', $this->getSession()->getPage()->getContent());
    self::assertStringNotContainsString('>6<', $this->getSession()->getPage()->getContent());

    $this->config('changelogify.settings')
      ->set('changelog_path', '/product-updates')
      ->save();
    \Drupal::service('router.builder')->rebuild();
    $this->drupalGet('/product-updates/feed.atom');
    $this->assertSession()->statusCodeEquals(200);
    $prefixedAtom = simplexml_load_string($this->getSession()->getPage()->getContent());
    self::assertNotFalse($prefixedAtom);
    $prefixedAtom->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
    $prefixedEntries = $prefixedAtom->xpath('/a:feed/a:entry');
    self::assertSame('urn:uuid:' . $uuid, (string) $prefixedEntries[0]->id);
    self::assertStringContainsString('/product-updates/', (string) $prefixedEntries[0]->link['href']);
  }

  /**
   * Creates a release in the requested editorial state.
   */
  private function release(string $title, string $state, string $text = 'Private content'): object {
    $release = \Drupal::entityTypeManager()->getStorage('changelogify_release')->create([
      'title' => $title,
      'release_date' => 1_700_000_000,
      'status' => $state === 'published',
      'editorial_state' => $state,
    ]);
    $release->setSections([
      'changed' => [[
        'id' => strtolower($state) . '-item',
        'text' => $text,
        'event_ids' => [],
      ],
      ],
    ])->save();
    return $release;
  }

}
