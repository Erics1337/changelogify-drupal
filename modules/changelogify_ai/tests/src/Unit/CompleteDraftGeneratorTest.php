<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify\ChangeSet\ChangeSet;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\ReleaseGeneratorInterface;
use Drupal\changelogify\ReleasePreview;
use Drupal\changelogify_ai\AiOperationManager;
use Drupal\changelogify_ai\CompleteDraftGenerator;
use Drupal\changelogify_ai\OutboundPayloadBuilder;
use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\Summarization\FakeSummarizer;
use Drupal\changelogify_ai\Summarization\SummarizationItem;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests that rejected AI output cannot create a release draft.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class CompleteDraftGeneratorTest extends TestCase {

  /**
   * Validation failure occurs before the core release generator is reached.
   */
  public function testMalformedOutputCannotCreateRelease(): void {
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->expects(self::never())->method('generateReleaseFromSelection');
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations(), $releaseGenerator, $this->database());
    $this->expectException(\UnexpectedValueException::class);
    $generator->generate([
      new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Evidence.'], []),
    ], new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], 'concise', FALSE, FALSE);
  }

  /**
   * A provider refusal cannot create a deterministic or AI release draft.
   */
  public function testProviderRefusalCannotCreateRelease(): void {
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->expects(self::never())->method('generateReleaseFromSelection');
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations('refusal'), $releaseGenerator, $this->database());
    $this->expectException(\UnexpectedValueException::class);
    $generator->generate([
      new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Evidence.'], []),
    ], new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], 'concise', FALSE, FALSE);
  }

  /**
   * A completed provider response cannot silently create an empty draft.
   */
  public function testEmptyCompletedResultRequiresExplicitConfirmation(): void {
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->expects(self::never())->method('generateReleaseFromSelection');
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations('empty'), $releaseGenerator, $this->database());
    $this->expectException(\UnexpectedValueException::class);
    $generator->generate([
      new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Evidence.'], []),
    ], new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], 'concise', FALSE, FALSE);
  }

  /**
   * Empty queued drafts require the same explicit confirmation as sync drafts.
   */
  public function testQueueRejectsUnconfirmedEmptySelection(): void {
    $generator = new CompleteDraftGenerator(
      $this->payloadBuilder(),
      $this->operations('success'),
      $this->createMock(ReleaseGeneratorInterface::class),
      $this->database(),
    );
    $this->expectException(\UnexpectedValueException::class);
    $generator->queue([], new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), [], [], 'concise', FALSE, FALSE);
  }

  /**
   * Queued results cannot apply evidence that disappeared after validation.
   */
  public function testQueuedResultRejectsStaleEvidence(): void {
    $changeSet = new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Evidence.'], []);
    $release = $this->createMock(ChangelogifyReleaseInterface::class);
    $release->expects(self::never())->method('setSections');
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->method('previewRange')->willReturn(new ReleasePreview(1, 2, [$changeSet]));
    $releaseGenerator->method('generateReleaseFromSelection')->willReturn($release);
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations('success'), $releaseGenerator, $this->database());
    $result = new SummarizationResult('completed', [
      new SummarizationItem('item-1', 'changed', 'Generated.', ['missing-source']),
    ]);
    $this->expectException(\UnexpectedValueException::class);
    $generator->finalizeQueued($result, new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], FALSE, FALSE);
  }

  /**
   * Queued results cannot introduce an unsupported release section.
   */
  public function testQueuedResultRejectsUnsupportedSection(): void {
    $changeSet = new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Evidence.'], []);
    $release = $this->createMock(ChangelogifyReleaseInterface::class);
    $release->expects(self::never())->method('setSections');
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->method('previewRange')->willReturn(new ReleasePreview(1, 2, [$changeSet]));
    $releaseGenerator->method('generateReleaseFromSelection')->willReturn($release);
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations('success'), $releaseGenerator, $this->database());
    $result = new SummarizationResult('completed', [
      new SummarizationItem('item-1', 'unsupported', 'Generated.', ['change-1']),
    ]);
    $this->expectException(\UnexpectedValueException::class);
    $generator->finalizeQueued($result, new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], FALSE, FALSE);
  }

  /**
   * A valid result uses only selected sources and always creates a draft.
   */
  public function testSuccessfulGenerationUsesSelectedEvidenceAndUnpublishes(): void {
    $release = $this->createMock(ChangelogifyReleaseInterface::class);
    $release->expects(self::once())->method('setUnpublished');
    $release->expects(self::once())->method('setNewRevision')->with(TRUE);
    $release->expects(self::once())->method('setSections')->with(self::callback(static function (array $sections): bool {
      return $sections['changed'][0]['id'] === 'change-1'
        && $sections['changed'][0]['text'] === 'Selected evidence.'
        && $sections['changed'][0]['event_ids'] === [1]
        && $sections['other'] === [];
    }));
    $release->expects(self::once())->method('setProvenance')->with([
      'version' => 1,
      'items' => [
        'change-1' => [
          'change_set_ids' => ['change-1'],
          'kind' => 'content',
          'section' => 'changed',
          'event_ids' => [1],
          'event_count' => 1,
          'evidence_status' => 'available',
          'events' => [],
        ],
      ],
    ]);
    $release->expects(self::once())->method('setRevisionLogMessage');
    $release->expects(self::once())->method('save');
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->expects(self::once())->method('generateReleaseFromSelection')->willReturn($release);
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations('success'), $releaseGenerator, $this->database());
    $result = $generator->generate([
      new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Selected evidence.'], []),
      new ChangeSet('change-2', 'content', 1, 1, [2], 'other', ['message' => 'Unselected evidence.'], []),
    ], new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], 'concise', FALSE, FALSE);
    self::assertSame($release, $result);
    self::assertSame(['omitted_source_ids' => [], 'warnings' => []], $generator->lastReport());
  }

  /**
   * Provider omissions and warnings remain available to the editor workflow.
   */
  public function testGenerationRetainsOmissionAndWarningReport(): void {
    $release = $this->createMock(ChangelogifyReleaseInterface::class);
    $release->method('setUnpublished');
    $release->method('setNewRevision');
    $release->method('setSections');
    $release->method('setProvenance');
    $release->method('setRevisionLogMessage');
    $release->method('save');
    $releaseGenerator = $this->createMock(ReleaseGeneratorInterface::class);
    $releaseGenerator->method('generateReleaseFromSelection')->willReturn($release);
    $generator = new CompleteDraftGenerator($this->payloadBuilder(), $this->operations('completed_with_report'), $releaseGenerator, $this->database());
    $generator->generate([
      new ChangeSet('change-1', 'content', 1, 1, [1], 'changed', ['message' => 'Selected evidence.'], []),
    ], new \DateTimeImmutable('@1'), new \DateTimeImmutable('@2'), ['change-1' => 'changed'], [], 'concise', FALSE, FALSE);
    self::assertSame([
      'omitted_source_ids' => ['change-1'],
      'warnings' => ['Deterministic provider warning.'],
    ], $generator->lastReport());
  }

  /**
   * Creates the minimum payload builder for one safe change set.
   */
  private function payloadBuilder(): OutboundPayloadBuilder {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('policy')->willReturn([]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    return new OutboundPayloadBuilder($factory);
  }

  /**
   * Creates a transaction-capable database mock.
   */
  private function database(): Connection {
    $transaction = new class extends Transaction {

      /**
       * Avoids constructing a real database transaction in a unit test.
       */
      public function __construct() {}

      /**
       * Avoids committing a mocked database transaction during destruction.
       */
      public function __destruct() {}

      /**
       * Records no state when the mocked transaction is rolled back.
       */
      public function rollBack(): void {}

    };
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    return $database;
  }

  /**
   * Creates an operation manager whose provider returns malformed output.
   */
  private function operations(string $mode = 'malformed'): AiOperationManager {
    /** @var array<string, mixed> $records */
    $records = [];
    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->willReturnCallback(static fn (string $key): mixed => $records[$key] ?? NULL);
    $store->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$records): void {
      $records[$key] = $value;
    });
    $keyValue = $this->createMock(KeyValueFactoryInterface::class);
    $keyValue->method('get')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(1);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1);
    $queues = $this->createMock(QueueFactory::class);
    $queues->method('get')->willReturn($this->createMock(QueueInterface::class));
    return new AiOperationManager(new FakeSummarizer($mode), new ResultValidator(), $keyValue, $lock, $account, $time, $this->createMock(LoggerInterface::class), $queues);
  }

}
