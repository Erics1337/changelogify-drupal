<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

/**
 * Builds Drupal AI chat DTOs only when the optional dependency is enabled.
 */
final class DrupalAiChatRequestFactory implements ChatRequestFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function create(string $systemPrompt, string $userPrompt, ?array $structuredSchema = NULL): object {
    $messageClass = 'Drupal\\ai\\OperationType\\Chat\\ChatMessage';
    $inputClass = 'Drupal\\ai\\OperationType\\Chat\\ChatInput';
    // @phpstan-ignore-next-line Optional Drupal AI classes resolve at runtime.
    $message = (new \ReflectionClass($messageClass))->newInstance('user', $userPrompt);
    // @phpstan-ignore-next-line Optional Drupal AI classes resolve at runtime.
    $input = (new \ReflectionClass($inputClass))->newInstance([$message]);
    // @phpstan-ignore-next-line Optional Drupal AI classes resolve at runtime.
    $input->setSystemPrompt($systemPrompt);
    if ($structuredSchema !== NULL) {
      // @phpstan-ignore-next-line Optional Drupal AI classes resolve at runtime.
      $input->setChatStructuredJsonSchema($structuredSchema);
    }
    return $input;
  }

}
