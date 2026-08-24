<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Indicates a provider failure that is safe to retry with a bounded policy.
 */
final class TransientSummarizationException extends SummarizationException {}
