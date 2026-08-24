<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify_ai\AiOperationManager;
use Drupal\changelogify_ai\ReleaseSuggestionManager;
use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\Summarization\FakeSummarizer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests editor-controlled, item-level AI suggestions without a provider.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class ReleaseSuggestionManagerTest extends TestCase {

  /**
   * A request neither saves nor changes the trusted release.
   */
  public function testSuggestionDoesNotMutateTheRelease(): void {
    $release = $this->release();
    $release->expects(self::never())->method('save');
    $result = $this->manager()->suggest($release, 'item-1', 'concise');
    self::assertSame('completed', $result->status);
    self::assertSame('Original text', $result->items[0]->text);
  }

  /**
   * Accepting updates only the chosen text in a new revision.
   */
  public function testAcceptChangesOnlySelectedText(): void {
    $release = $this->release();
    $release->expects(self::once())->method('setNewRevision')->with(TRUE);
    $release->expects(self::once())->method('setRevisionUserId')->with(9);
    $release->expects(self::once())->method('setRevisionCreationTime')->with(1000);
    $release->expects(self::once())->method('setRevisionLogMessage');
    $release->expects(self::once())->method('setSections')->with([
      'changed' => [
        ['id' => 'item-1', 'text' => 'Clearer text', 'event_ids' => ['event-1']],
        ['id' => 'item-2', 'text' => 'Keep this text', 'event_ids' => ['event-2']],
      ],
      'other' => [
        ['id' => 'manual-1', 'text' => 'Manual text', 'event_ids' => []],
      ],
    ]);
    $release->expects(self::once())->method('save');
    $manager = $this->manager();
    $result = $manager->suggest($release, 'item-1', 'concise');
    $manager->accept($release, 'item-1', 'Clearer text', (string) $result->operationId);
  }

  /**
   * Deliberate regeneration uses a distinct idempotency key.
   */
  public function testRegenerationUsesExplicitAttempt(): void {
    $release = $this->release();
    $manager = $this->manager();
    $first = $manager->suggest($release, 'item-1', 'concise', 0);
    $second = $manager->suggest($release, 'item-1', 'concise', 1);
    self::assertNotSame($first->operationId, $second->operationId);
    self::assertSame('completed', $second->status);
  }

  /**
   * Manual items require an explicit opt-in even with an available provider.
   */
  public function testManualItemRequiresExplicitOptIn(): void {
    $release = $this->release();
    self::assertFalse($this->manager(FALSE)->canSuggest($release, 'manual-1'));
    self::assertTrue($this->manager(TRUE)->canSuggest($release, 'manual-1'));
  }

  /**
   * An invalid operation cannot persist a release revision.
   */
  public function testAcceptValidatesOperationBeforeSaving(): void {
    $release = $this->release();
    $release->expects(self::never())->method('save');
    $this->expectException(\UnexpectedValueException::class);
    $this->manager()->accept($release, 'item-1', 'Clearer text', 'missing-operation');
  }

  /**
   * The action is hidden when the configured provider is unavailable.
   */
  public function testProviderAvailabilityIsRequired(): void {
    self::assertFalse($this->manager(FALSE, 'missing_capability')->canSuggest($this->release(), 'item-1'));
  }

  /**
   * Returns a release fixture with stable IDs, order, and provenance.
   */
  private function release(): ChangelogifyReleaseInterface&MockObject {
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface&\PHPUnit\Framework\MockObject\MockObject $release */
    $release = $this->createMock(ChangelogifyReleaseInterface::class);
    $release->method('getSections')->willReturn([
      'changed' => [
        ['id' => 'item-1', 'text' => 'Original text', 'event_ids' => ['event-1']],
        ['id' => 'item-2', 'text' => 'Keep this text', 'event_ids' => ['event-2']],
      ],
      'other' => [
        ['id' => 'manual-1', 'text' => 'Manual text', 'event_ids' => []],
      ],
    ]);
    $release->method('uuid')->willReturn('release-uuid');
    $release->method('getRevisionId')->willReturn(4);
    $release->method('id')->willReturn(7);
    return $release;
  }

  /**
   * Builds an available, deterministic manager with no network dependency.
   */
  private function manager(bool $allowManual = FALSE, string $providerMode = 'success'): ReleaseSuggestionManager {
    /** @var array<string, mixed> $records */
    $records = [];
    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->willReturnCallback(static function (string $key) use (&$records): mixed {
      return $records[$key] ?? NULL;
    });
    $store->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$records): void {
      $records[$key] = $value;
    });
    $keyValue = $this->createMock(KeyValueFactoryInterface::class);
    $keyValue->method('get')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(9);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($this->createMock(QueueInterface::class));
    $operations = new AiOperationManager(new FakeSummarizer($providerMode), new ResultValidator(), $keyValue, $lock, $account, $time, $this->createMock(LoggerInterface::class), $queueFactory);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('policy.allow_manual_humanization')->willReturn($allowManual);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    $database = $this->createMock(Connection::class);
    $transaction = $this->getMockBuilder(Transaction::class)
      ->setConstructorArgs([$database, 'test', 'test-id'])
      ->onlyMethods(['rollBack', '__destruct'])
      ->getMock();
    $database->method('startTransaction')->willReturn($transaction);
    return new ReleaseSuggestionManager($operations, $account, $time, $configFactory, $database);
  }

}
