<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\changelogify\ReleaseGeneratorInterface;
use Drupal\changelogify_ai\OutboundPayloadBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a no-network preview of the policy-filtered outbound payload.
 */
final class PayloadPreviewController extends ControllerBase {

  public function __construct(protected ReleaseGeneratorInterface $releaseGenerator, protected OutboundPayloadBuilder $payloadBuilder) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ReleaseGeneratorInterface::class),
      $container->get(OutboundPayloadBuilder::class),
    );
  }

  /**
   * Renders the data portion exactly as prompt construction receives it.
   */
  public function view(): array {
    $preview = $this->releaseGenerator->previewSinceLast();
    $payload = $this->payloadBuilder->build($preview->changeSets);
    try {
      $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [
        '#type' => 'item',
        '#title' => $this->t('Payload preview unavailable'),
        '#plain_text' => $this->t('The selected evidence contains text that cannot be encoded safely. Correct the source text before enabling external processing.'),
        '#cache' => ['max-age' => 0],
      ];
    }
    return [
      'description' => [
        '#markup' => $this->t('This is the exact eligible, policy-filtered data portion of a representative request. Event categories excluded by the AI eligibility settings are not shown. No provider request was made.'),
      ],
      'payload' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#plain_text' => $encodedPayload,
      ],
      '#cache' => [
        'tags' => ['config:changelogify_ai.settings', 'config:changelogify.settings'],
        'max-age' => 0,
      ],
    ];
  }

}
