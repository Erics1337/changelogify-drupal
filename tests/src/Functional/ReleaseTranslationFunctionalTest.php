<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\block\Entity\Block;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\block\Functional\BlockTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests negotiated release translations across every public channel.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseTranslationFunctionalTest extends BlockTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'changelogify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests translation selection, privacy, slugs, fallback, and provenance.
   */
  public function testReleaseTranslationNegotiation(): void {
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('content_translation.manager')
      ->setEnabled('changelogify_release', 'changelogify_release', TRUE);
    $this->drupalGet('/admin/config/regional/language/detection');
    $this->submitForm([
      'language_interface[enabled][language-url]' => TRUE,
      'language_interface[enabled][language-selected]' => TRUE,
      'language_content[enabled][language-url]' => TRUE,
      'language_content[enabled][language-interface]' => TRUE,
    ], 'Save settings');
    $this->drupalGet('/admin/config/regional/language/detection/url');
    $this->submitForm(['prefix[en]' => 'en', 'prefix[fr]' => 'fr'], 'Save configuration');

    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertNotNull($anonymous);
    $anonymous->grantPermission('view changelogify releases')->save();
    $block = $this->placeBlock('changelogify_latest_release', [
      'id' => 'translated-release-test',
      'show_changelog_link' => FALSE,
    ]);
    self::assertInstanceOf(Block::class, $block);

    $storage = \Drupal::entityTypeManager()->getStorage('changelogify_release');
    $release = $storage->create([
      'title' => 'English release',
      'langcode' => 'en',
      'release_date' => 1_700_000_100,
      'editorial_state' => 'published',
    ]);
    $release->setSections([
      'changed' => [[
        'id' => 'stable-item',
        'text' => 'English public note',
        'event_ids' => [42],
      ],
      ],
    ]);
    $release->setProvenance([
      'version' => 1,
      'items' => [
        'stable-item' => [
          'change_set_id' => 'change-1',
          'kind' => 'event',
          'section' => 'changed',
          'event_ids' => [42],
          'event_count' => 1,
          'evidence_status' => 'missing',
          'events' => [],
        ],
      ],
    ])->save();
    $fr = $release->addTranslation('fr', [
      'title' => 'Version française',
      'slug' => 'version-francaise',
      'editorial_state' => 'published',
      'status' => TRUE,
    ]);
    $fr->setSections([
      'changed' => [[
        'id' => 'stable-item',
        'text' => 'Note publique française',
        'event_ids' => [42],
      ],
      ],
    ])->save();
    self::assertSame($release->getProvenance(), $fr->getProvenance());
    self::assertSame('stable-item', $fr->getSections()['changed'][0]['id']);
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertTrue($release->hasTranslation('fr'));
    self::assertSame('Version française', $release->getTranslation('fr')->getTitle());
    self::assertTrue($release->getTranslation('fr')->isPublished());
    $this->drupalGet($release->toUrl('drupal:content-translation-overview'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('French');

    $sourceOnly = $storage->create([
      'title' => 'English fallback release',
      'langcode' => 'en',
      'release_date' => 1_700_000_000,
      'editorial_state' => 'published',
    ]);
    $sourceOnly->setSections([
      'changed' => [[
        'id' => 'fallback-item',
        'text' => 'English fallback note',
        'event_ids' => [],
      ],
      ],
    ])->save();

    $this->drupalLogout();
    $this->drupalGet('/fr/changelog');
    $this->assertSession()->pageTextContains('Version française');
    $this->assertSession()->pageTextNotContains('English release');
    $this->assertSession()->elementExists('css', 'a[href$="/fr/changelog/version-francaise"]');
    $this->drupalGet('/fr/changelog/version-francaise');
    $this->assertSession()->pageTextContains('Note publique française');
    $this->drupalGet('/fr/changelog/english-release');
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('/fr/changelog/feed.rss');
    $this->assertSession()->pageTextContains('Version française');
    $this->assertSession()->pageTextContains('Note publique française');
    $this->drupalGet('/fr/changelog/api/v1/releases/version-francaise');
    $api = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('fr', $api['release']['language']);
    self::assertSame('Note publique française', $api['release']['sections']['changed']['items'][0]['text']);
    $this->drupalGet('/fr/');
    $this->assertSession()->pageTextContains('Version française');

    $fr->setEditorialState('draft')->save();
    $this->drupalGet('/fr/changelog');
    $this->assertSession()->pageTextNotContains('Version française');
    $this->assertSession()->pageTextNotContains('English release');
    $this->drupalGet('/fr/changelog/version-francaise');
    $this->assertSession()->statusCodeEquals(404);
    $fr->setEditorialState('published')->save();

    $this->config('changelogify.settings')->set('translation_fallback', 'hide')->save();
    $this->drupalGet('/fr/changelog');
    $this->assertSession()->pageTextNotContains('English fallback release');
    $this->config('changelogify.settings')->set('translation_fallback', 'fallback')->save();
    $this->drupalGet('/fr/changelog');
    $this->assertSession()->pageTextContains('English fallback release');
    $this->assertSession()->pageTextNotContains('Shown in English because a translation is unavailable.');
    $this->config('changelogify.settings')->set('translation_fallback', 'label')->save();
    $this->drupalGet('/fr/changelog');
    $this->assertSession()->pageTextContains('English fallback release');
    $this->assertSession()->pageTextContains('Shown in English because a translation is unavailable.');
  }

}
