<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\EventManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard controller for Changelogify.
 */
class DashboardController extends ControllerBase
{

    /**
     * Constructs a DashboardController.
     */
    public function __construct(
        protected EventManagerInterface $eventManager,
        private readonly EntityTypeManagerInterface $dashboardEntityTypeManager,
        private readonly DateFormatterInterface $dateFormatter,
        private readonly TimeInterface $time,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get(EventManagerInterface::class),
            $container->get('entity_type.manager'),
            $container->get('date.formatter'),
            $container->get('datetime.time'),
        );
    }

    /**
     * Displays the dashboard.
     */
    public function dashboard(): array
    {
        $now = $this->time->getRequestTime();
        $seven_days_ago = $now - (7 * 24 * 60 * 60);
        $thirty_days_ago = $now - (30 * 24 * 60 * 60);

        $events_7d = $this->eventManager->getEventCountSince($seven_days_ago);
        $events_30d = $this->eventManager->getEventCountSince($thirty_days_ago);
        $events_since_last = $this->eventManager->getEventCountSinceLastRelease();

        // Get recent releases.
        $release_storage = $this->dashboardEntityTypeManager->getStorage('changelogify_release');
        $release_ids = $release_storage->getQuery()
            ->accessCheck(TRUE)
            ->sort('release_date', 'DESC')
            ->range(0, 5)
            ->execute();
        $releases = $release_storage->loadMultiple($release_ids);

        $build = [
            '#type' => 'container',
            '#attributes' => ['class' => ['changelogify-dashboard']],
            '#attached' => [
                'library' => ['changelogify/dashboard'],
            ],
            'stats' => [
                '#type' => 'container',
                '#attributes' => ['class' => ['changelogify-stats']],
                'events_7d' => [
                    '#type' => 'container',
                    '#attributes' => ['class' => ['stat-card']],
                    'count' => [
                        '#type' => 'html_tag',
                        '#tag' => 'strong',
                        '#value' => (string) $events_7d,
                    ],
                    'label' => [
                        '#type' => 'html_tag',
                        '#tag' => 'span',
                        '#value' => $this->t('Events in the last 7 days'),
                    ],
                ],
                'events_30d' => [
                    '#type' => 'container',
                    '#attributes' => ['class' => ['stat-card']],
                    'count' => [
                        '#type' => 'html_tag',
                        '#tag' => 'strong',
                        '#value' => (string) $events_30d,
                    ],
                    'label' => [
                        '#type' => 'html_tag',
                        '#tag' => 'span',
                        '#value' => $this->t('Events in the last 30 days'),
                    ],
                ],
                'events_since_last' => [
                    '#type' => 'container',
                    '#attributes' => ['class' => ['stat-card']],
                    'count' => [
                        '#type' => 'html_tag',
                        '#tag' => 'strong',
                        '#value' => (string) $events_since_last,
                    ],
                    'label' => [
                        '#type' => 'html_tag',
                        '#tag' => 'span',
                        '#value' => $this->t('Events since the last release'),
                    ],
                ],
            ],
            'actions' => [
                '#type' => 'container',
                '#attributes' => ['class' => ['changelogify-actions']],
                'generate' => [
                    '#type' => 'link',
                    '#title' => $this->t('Generate New Release'),
                    '#url' => Url::fromRoute('changelogify.generate_release'),
                    '#attributes' => [
                        'class' => ['button', 'button--primary'],
                    ],
                ],
                'view_releases' => [
                    '#type' => 'link',
                    '#title' => $this->t('View All Releases'),
                    '#url' => Url::fromRoute('entity.changelogify_release.collection'),
                    '#attributes' => [
                        'class' => ['button'],
                    ],
                ],
            ],
            'recent_releases' => [
                '#type' => 'container',
                '#attributes' => ['class' => ['changelogify-recent']],
                'title' => [
                    '#type' => 'html_tag',
                    '#tag' => 'h2',
                    '#value' => $this->t('Recent Releases'),
                ],
                'list' => $this->buildReleaseList($releases),
            ],
        ];

        (new CacheableMetadata())
            ->addCacheTags(array_merge(
                $this->dashboardEntityTypeManager
                    ->getDefinition('changelogify_event')
                    ->getListCacheTags(),
                $this->dashboardEntityTypeManager
                    ->getDefinition('changelogify_release')
                    ->getListCacheTags(),
            ))
            ->addCacheContexts(['user.permissions'])
            ->applyTo($build);

        return $build;
    }

    /**
     * Builds a simple list of releases.
     */
    protected function buildReleaseList(array $releases): array
    {
        if (empty($releases)) {
            return [
                '#markup' => $this->t('No releases yet.'),
            ];
        }

        $items = [];
        foreach ($releases as $release) {
            $status = $release->isPublished() ? $this->t('Published') : $this->t('Draft');
            $date = $this->dateFormatter->format($release->getReleaseDate(), 'short');

            $items[] = [
                '#type' => 'link',
                '#title' => $this->t('@title (@status) - @date', [
                    '@title' => $release->getTitle(),
                    '@status' => $status,
                    '@date' => $date,
                ]),
                '#url' => $release->toUrl('edit-form'),
            ];
        }

        return [
            '#theme' => 'item_list',
            '#items' => $items,
        ];
    }

}
