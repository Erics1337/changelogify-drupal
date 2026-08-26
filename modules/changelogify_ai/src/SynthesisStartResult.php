<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

/**
 * Describes whether synthesis work was newly queued or safely reused.
 */
final readonly class SynthesisStartResult {

  public function __construct(public string $jobId, public bool $reused) {}

}
