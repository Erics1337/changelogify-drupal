<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\Core\Entity\RevisionableStorageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests release revision storage and state/status synchronization.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseWorkflowKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests every save creates a recoverable revision.
   */
  public function testReleaseRevisions(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    self::assertInstanceOf(RevisionableStorageInterface::class, $storage);
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => 'Original title',
      'release_date' => 1_700_000_000,
      'status' => FALSE,
    ]);
    $release->setRevisionLogMessage('Initial draft.')->save();
    $originalRevisionId = (int) $release->getRevisionId();
    $release->setTitle('Edited title');
    $release->setEditorialState('published');
    $release->setRevisionLogMessage('Published.')->save();

    self::assertNotSame($originalRevisionId, (int) $release->getRevisionId());
    self::assertTrue($release->isPublished());
    $original = $storage->loadRevision($originalRevisionId);
    self::assertNotNull($original);
    self::assertSame('Original title', $original->getTitle());
    self::assertSame('draft', $original->getEditorialState());
    self::assertFalse($original->isPublished());

    $release->setEditorialState('archived')->save();
    self::assertSame('archived', $release->getEditorialState());
    self::assertFalse($release->isPublished());
  }

}
