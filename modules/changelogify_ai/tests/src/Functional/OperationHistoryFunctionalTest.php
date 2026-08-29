<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Functional;

use Drupal\changelogify_ai\AiOperationHistoryRepository;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the privacy-bounded AI operation history.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
#[RunTestsInSeparateProcesses]
final class OperationHistoryFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['changelogify', 'ai', 'changelogify_ai'];

  /**
   * Tests durable labels and missing release handling.
   */
  public function testWholeReleaseRewriteAndDeletedRelease(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'view changelogify ai history',
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    $release = $storage->create([
      'title' => 'History target release',
      'release_date' => 1_700_000_000,
      'status' => FALSE,
      'editorial_state' => 'draft',
    ]);
    $release->save();
    $history = $this->container->get(AiOperationHistoryRepository::class);
    $history->save([
      'id' => str_repeat('a', 64),
      'type' => 'humanize_release',
      'status' => 'completed',
      'created' => 1_700_000_100,
      'release_id' => (int) $release->id(),
    ]);
    $history->save([
      'id' => str_repeat('b', 64),
      'type' => 'humanize_item',
      'status' => 'failed',
      'created' => 1_700_000_200,
    ]);

    $this->drupalGet('/admin/config/development/changelogify/ai/history');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Operations still processing or awaiting editorial review are shown first');
    $this->assertSession()->pageTextContains('Whole-release rewrite');
    $this->assertSession()->pageTextContains('Suggestion ready');
    $this->assertSession()->linkExists('Review draft');
    $this->assertSession()->elementExists(
      'css',
      'select[name="type"] option[value="humanize_release"]',
    );
    $rows = $this->getSession()->getPage()->findAll('css', 'table tbody tr');
    self::assertCount(2, $rows);
    self::assertStringContainsString('Whole-release rewrite', $rows[0]->getText());
    self::assertStringContainsString('Release-note rewrite', $rows[1]->getText());

    $release->delete();
    $this->drupalGet('/admin/config/development/changelogify/ai/history', [
      'query' => ['type' => 'humanize_release'],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Whole-release rewrite');
    $this->assertSession()->pageTextContains('Release no longer available');
    $this->assertSession()->linkNotExists('Review draft');
  }

}
