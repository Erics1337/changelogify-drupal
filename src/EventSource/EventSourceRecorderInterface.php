<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify\EventInput;

/**
 * Records events subject to their source configuration.
 */
interface EventSourceRecorderInterface {

  /**
   * Records an event when its source is enabled.
   */
  public function record(EventSourceInterface $source, EventInput $input): ?ChangelogifyEventInterface;

  /**
   * Determines whether a source is enabled.
   */
  public function isEnabled(EventSourceInterface $source): bool;

}
