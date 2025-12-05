<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\changelogify\EventManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard controller for Changelogify.
 */
class DashboardController extends ControllerBase
{

    /**
     * The event manager.
     */
    protected EventManagerInterface $eventManager;

    /**
     * Constructs a DashboardController.
     */
    public function __construct(EventManagerInterface $event_manager)
    {
        $this->eventManager = $event_manager;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {
        return new static(
            $container->get('changelogify.event_manager')
        );
    }

    /**
     * Displays the dashboard.
     */
    public function dashboard(): array
    {
        $now = \Drupal::time()->getRequestTime();
        $seven_days_ago = $now - (7 * 24 * 60 * 60);
        $thirty_days_ago = $now - (30 * 24 * 60 * 60);

        $events_7d = $this->eventManager->getEventCountSince($seven_days_ago);
        $events_30d = $this->eventManager->getEventCountSince($thirty_days_ago);
        $events_since_last = count($this->eventManager->getEventsSinceLastRelease());

        // Get recent releases.
        $release_storage = $this->entityTypeManager()->getStorage('changelogify_release');
        $release_ids = $release_storage->getQuery()
            ->accessCheck(TRUE)
            ->sort('release_date', 'DESC')
            ->range(0, 5)
            ->execute();
        $releases = $release_storage->loadMultiple($release_ids);

        return [
            '#theme' => 'changelogify_dashboard',
            '#attached' => [
                'library' => ['changelogify/dashboard'],
            ],
            'stats' => [
                '#type' => 'container',
                '#attributes' => ['class' => ['changelogify-stats']],
                'events_7d' => [
                    '#type' => 'html_tag',
                    '#tag' => 'div',
                    '#attributes' => ['class' => ['stat-card']],
                    '#value' => $this->t('<strong>@count</strong> events in last 7 days', ['@count' => $events_7d]),
                ],
                'events_30d' => [
                    '#type' => 'html_tag',
                    '#tag' => 'div',
                    '#attributes' => ['class' => ['stat-card']],
                    '#value' => $this->t('<strong>@count</strong> events in last 30 days', ['@count' => $events_30d]),
                ],
                'events_since_last' => [
                    '#type' => 'html_tag',
                    '#tag' => 'div',
                    '#attributes' => ['class' => ['stat-card']],
                    '#value' => $this->t('<strong>@count</strong> events since last release', ['@count' => $events_since_last]),
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
                    '#url' => Url::fromRoute('changelogify.release_list'),
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
                    '#tag' => 'h3',
                    '#value' => $this->t('Recent Releases'),
                ],
                'list' => $this->buildReleaseList($releases),
            ],
        ];
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
            $date = \Drupal::service('date.formatter')->format($release->getReleaseDate(), 'short');

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
