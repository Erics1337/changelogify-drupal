<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

/**
 * Creates a provider-neutral chat request at the Drupal AI boundary.
 */
interface ChatRequestFactoryInterface {

  /**
   * Creates one user-message chat request with a bounded system prompt.
   */
  public function create(string $systemPrompt, string $userPrompt, ?array $structuredSchema = NULL): object;

}
