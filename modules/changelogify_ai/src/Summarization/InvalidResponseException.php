<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Indicates a non-conforming provider response that must not be retried.
 */
final class InvalidResponseException extends SummarizationException {}
