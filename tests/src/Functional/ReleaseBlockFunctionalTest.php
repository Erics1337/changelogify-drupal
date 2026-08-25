<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\block\Entity\Block;
use Drupal\Core\Form\FormState;
use Drupal\Tests\block\Functional\BlockTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests cacheable public release blocks and their configuration.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseBlockFunctionalTest extends BlockTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'changelogify'];

  /**
   * Tests empty, published-only, configuration, path, and invalidation states.
   */
  public function testRecentAndLatestReleaseBlocks(): void {
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    self::assertNotNull($anonymous);
    $anonymous->grantPermission('view changelogify releases')->save();
    $viewer = $this->drupalCreateUser(['view changelogify releases']);
    $this->drupalLogin($viewer);
    $this->config('changelogify.settings')
      ->set('changelog_path', '/product-updates')
      ->save();

    $recent = $this->placeBlock('changelogify_recent_releases', [
      'id' => 'changelogify_recent_test',
      'item_count' => 2,
      'show_date' => FALSE,
      'show_version' => TRUE,
      'sections' => [
        'added' => TRUE,
        'changed' => FALSE,
        'fixed' => FALSE,
        'removed' => FALSE,
        'security' => FALSE,
        'other' => FALSE,
      ],
      'show_changelog_link' => TRUE,
    ]);
    $latest = $this->placeBlock('changelogify_latest_release', [
      'id' => 'changelogify_latest_test',
      'show_changelog_link' => FALSE,
    ]);
    self::assertInstanceOf(Block::class, $recent);
    self::assertInstanceOf(Block::class, $latest);

    $this->drupalGet('<front>');
    $this->assertSession()->pageTextContains('No releases have been published yet.');

    $draft = $this->release('Private draft', 300, FALSE, 'Draft-only text');
    $older = $this->release('Older public release', 100, TRUE, 'Older added text', '1.0.0');
    $newer = $this->release('Newest public release', 200, TRUE, 'Newest added text', '1.1.0');

    $this->drupalGet('<front>');
    $this->assertSession()->pageTextNotContains($draft->getTitle());
    $this->assertSession()->pageTextContains($newer->getTitle());
    $this->assertSession()->pageTextContains($older->getTitle());
    $this->assertSession()->pageTextContains('Newest added text');
    $this->assertSession()->elementTextNotContains(
      'css',
      '#block-changelogify-recent-test',
      'Changed-section text',
    );
    $this->assertSession()->pageTextContains('Version 1.1.0');
    $this->assertSession()->elementExists('css', 'a[href="/product-updates"]');
    $this->assertSession()->elementNotExists(
      'css',
      '#block-changelogify-recent-test .changelogify-release-block__meta time',
    );

    $plugin = \Drupal::service('plugin.manager.block')->createInstance(
      'changelogify_recent_releases',
      $recent->getPlugin()->getConfiguration(),
    );
    $build = $plugin->build();
    self::assertContains('changelogify_release_list', $build['#cache']['tags']);
    self::assertContains($newer->getCacheTags()[0], $build['#cache']['tags']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    self::assertContains('languages:language_content', $build['#cache']['contexts']);

    $newer->setTitle('Updated newest release')->save();
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextContains('Updated newest release');
    $this->assertSession()->pageTextNotContains('Newest public release');

    $newer->setEditorialState('draft')->setPublished(FALSE)->save();
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextNotContains('Updated newest release');
    $this->assertSession()->pageTextContains('Older public release');

    $older->delete();
    $this->drupalGet('<front>');
    $this->assertSession()->pageTextContains('No releases have been published yet.');

    $invalid = new FormState();
    $invalid->setValues(['item_count' => 21, 'sections' => []]);
    $plugin->blockValidate([], $invalid);
    self::assertTrue($invalid->hasAnyErrors());
  }

  /**
   * Creates one release with content in included and excluded sections.
   */
  private function release(string $title, int $date, bool $published, string $addedText, string $version = ''): object {
    $release = \Drupal::entityTypeManager()->getStorage('changelogify_release')->create([
      'title' => $title,
      'release_date' => $date,
      'version' => $version,
      'status' => $published,
      'editorial_state' => $published ? 'published' : 'draft',
    ]);
    $release->setSections([
      'added' => [
        [
          'id' => $title . '-added',
          'text' => $addedText,
          'event_ids' => [],
        ],
      ],
      'changed' => [
        [
          'id' => $title . '-changed',
          'text' => 'Changed-section text',
          'event_ids' => [],
        ],
      ],
    ])->save();
    return $release;
  }

}
