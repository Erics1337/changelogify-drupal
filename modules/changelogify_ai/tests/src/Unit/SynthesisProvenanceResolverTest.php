<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\Summarization\SummarizationItem;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\SynthesisProvenanceResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests direct synthesis provenance and server-side coverage.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisProvenanceResolverTest extends TestCase {

  /**
   * Combined final notes resolve original sources and events.
   */
  public function testFinalizesCombinedNoteAndCompleteCoverage(): void {
    $resolver = new SynthesisProvenanceResolver();
    $evidence = $this->evidence(3);
    $index = $resolver->sourceIndex($evidence, [
      'change-1' => $this->provenance(1),
      'change-2' => $this->provenance(2),
      'change-3' => $this->provenance(3),
    ]);
    $result = new SummarizationResult('completed', [
      new SummarizationItem('note-1', 'changed', 'Combined factual note.', ['change-1', 'change-2']),
    ]);

    $final = $resolver->finalize($result, $evidence, $index, [
      'editor' => ['change-4'],
      'policy' => ['change-5', 'change-6'],
    ]);

    self::assertSame(['change-1', 'change-2'], $final['result']->items[0]->sourceIds);
    self::assertSame(['change-3'], $final['result']->omittedSourceIds);
    self::assertSame([1, 2], $final['provenance']['items']['note-1']['event_ids']);
    self::assertArrayNotHasKey('events', $final['provenance']['items']['note-1']);
    self::assertSame(['1', '2'], $final['provenance']['items']['note-1']['event_snapshot_ids']);
    self::assertSame(3, $final['coverage']['evidence_considered']);
    self::assertSame(2, $final['coverage']['evidence_cited']);
    self::assertSame(1, $final['coverage']['eligible_not_surfaced']);
    self::assertSame(1, $final['coverage']['excluded_by_editor']);
    self::assertSame(2, $final['coverage']['excluded_by_policy']);
  }

  /**
   * Event detail remains globally bounded while IDs and counts are complete.
   */
  public function testSourceSnapshotsAreGloballyBounded(): void {
    $resolver = new SynthesisProvenanceResolver();
    $evidence = $this->evidence(2);
    $events = [];
    for ($id = 1; $id <= 150; $id++) {
      $events[] = ['event_id' => $id, 'event_type' => 'content.updated', 'secret' => 'excluded'];
    }
    $index = $resolver->sourceIndex($evidence, [
      'change-1' => ['event_ids' => range(1, 150), 'event_count' => 150, 'events' => $events],
      'change-2' => ['event_ids' => range(151, 300), 'event_count' => 150, 'events' => $events],
    ]);

    self::assertCount(150, $index['change-1']['events']);
    self::assertCount(50, $index['change-2']['events']);
    self::assertSame(300, count($index['change-1']['event_ids']) + count($index['change-2']['event_ids']));
    self::assertArrayNotHasKey('secret', $index['change-1']['events'][0]);
    self::assertTrue($index['change-2']['snapshots_truncated']);
  }

  /**
   * Unknown or synthetic source references are rejected.
   */
  public function testRejectsUnknownSourceReferences(): void {
    $resolver = new SynthesisProvenanceResolver();
    $cases = [
      'unknown' => [['missing'], $this->evidence(1)],
      'synthetic' => [['candidate'], [
        'candidate' => ['kind' => 'synthesis_candidate'],
      ],
      ],
    ];
    foreach ($cases as [$sourceIds, $evidence]) {
      try {
        $resolver->resolveSourceIds($sourceIds, $evidence);
        self::fail('Hostile provenance was accepted.');
      }
      catch (\UnexpectedValueException) {
        self::assertTrue(TRUE);
      }
    }
  }

  /**
   * Creates minimal original evidence documents.
   */
  private function evidence(int $count): array {
    $evidence = [];
    for ($index = 1; $index <= $count; $index++) {
      $id = "change-{$index}";
      $evidence[$id] = ['id' => $id, 'event_count' => 1, 'evidence_status' => 'available'];
    }
    return $evidence;
  }

  /**
   * Creates one trusted source provenance record.
   */
  private function provenance(int $id): array {
    return [
      'event_ids' => [$id],
      'event_count' => 1,
      'evidence_status' => 'available',
      'events' => [['event_id' => $id, 'event_type' => 'content.updated']],
    ];
  }

}
