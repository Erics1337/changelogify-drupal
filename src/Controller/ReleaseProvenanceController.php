<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays privacy-bounded release provenance to release managers.
 */
final class ReleaseProvenanceController extends ControllerBase {

  public function __construct(
    private readonly ReleaseProvenanceManagerInterface $provenanceManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(ReleaseProvenanceManagerInterface::class));
  }

  /**
   * Builds the provenance view.
   */
  public function view(ChangelogifyReleaseInterface $changelogify_release): array {
    $json = Json::encode($this->provenanceManager
      ->getResolvedProvenance($changelogify_release));
    return [
      '#type' => 'item',
      '#title' => $this->t('Minimal release provenance'),
      '#markup' => '<pre>' . Html::escape($json) . '</pre>',
    ];
  }

}
