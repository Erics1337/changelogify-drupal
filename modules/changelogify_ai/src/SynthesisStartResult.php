<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

/**
 * Describes whether synthesis work was newly queued or safely reused.
 */
final class SynthesisStartResult {

  public function __construct(
    public readonly string $jobId,
    public readonly bool $reused,
  ) {}

}
