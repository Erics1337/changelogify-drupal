<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\changelogify\Entity\ChangelogifyRelease;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Changelogify admin interface.
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
  protected static $modules = ['changelogify', 'node', 'user', 'field', 'text', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
  }

  /**
   * Tests that the dashboard page loads.
   */
  public function testDashboardAccessAndReleaseGeneration(): void {
    $user = $this->drupalCreateUser([
      'administer changelogify',
      'manage changelogify releases',
      'view changelogify releases',
      'access administration pages',
    ]);

    $this->drupalLogin($user);

    // Visit the dashboard.
    $this->drupalGet('/admin/config/development/changelogify');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Changelogify');

    // Visit the release list.
    $this->drupalGet('/admin/content/changelogify/releases');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Releases');

    Node::create([
      'type' => 'page',
      'title' => 'Welcome page',
      'status' => 1,
    ])->save();

    $this->drupalGet('/admin/config/development/changelogify/generate');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Generate Release');
    $this->assertSession()->pageTextContains('Draft release');
    $this->assertSession()->pageTextContains('Release Sections');
    $this->assertSession()->pageTextContains('Welcome page');
  }

  /**
   * Tests the public changelog markup and attached library.
   */
  public function testPublicChangelogRendering(): void {
    $user = $this->drupalCreateUser([
      'manage changelogify releases',
      'view changelogify releases',
      'access administration pages',
    ]);

    $this->drupalLogin($user);

    $release = ChangelogifyRelease::create([
      'title' => 'March Release',
      'version' => '1.2.0',
      'release_date' => \Drupal::time()->getRequestTime(),
      'status' => TRUE,
      'uid' => $user->id(),
    ]);
    $release->setSections([
      'added' => [
        [
          'id' => 'item-1',
          'text' => 'Published the refreshed About Us page',
          'event_ids' => [],
        ],
      ],
      'changed' => [],
      'fixed' => [],
      'removed' => [],
      'security' => [],
      'other' => [],
    ]);
    $release->save();

    $this->drupalGet('/changelog');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('March Release');
    $this->assertSession()->responseContains('css/changelogify.css');
    $this->assertSession()->elementExists('css', '.changelogify-release-list');

    $this->drupalGet('/changelog/' . $release->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Version 1.2.0');
    $this->assertSession()->pageTextContains('Published the refreshed About Us page');
    $this->assertSession()->elementExists('css', '.changelogify-release__section');
  }

}
