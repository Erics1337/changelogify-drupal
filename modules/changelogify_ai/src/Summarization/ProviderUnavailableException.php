<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Indicates no safe configured provider is ready to serve a request.
 */
final class ProviderUnavailableException extends SummarizationException {}
