<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Controller for the release listing page.
 */
class ReleaseListController extends ControllerBase
{

    /**
     * Displays the admin release listing.
     */
    public function listing(): array
    {
        $storage = $this->entityTypeManager()->getStorage('changelogify_release');

        $header = [
            ['data' => $this->t('Title'), 'field' => 'title'],
            ['data' => $this->t('Version')],
            ['data' => $this->t('Date'), 'field' => 'release_date', 'sort' => 'desc'],
            ['data' => $this->t('Status')],
            ['data' => $this->t('Operations')],
        ];

        $query = $storage->getQuery()
            ->accessCheck(TRUE)
            ->tableSort($header)
            ->pager(25);

        $release_ids = $query->execute();
        $releases = $storage->loadMultiple($release_ids);

        $rows = [];
        foreach ($releases as $release) {
            $operations = [
                '#type' => 'operations',
                '#links' => [
                    'edit' => [
                        'title' => $this->t('Edit'),
                        'url' => $release->toUrl('edit-form'),
                    ],
                    'delete' => [
                        'title' => $this->t('Delete'),
                        'url' => $release->toUrl('delete-form'),
                    ],
                    'view' => [
                        'title' => $this->t('View'),
                        'url' => $release->toUrl('canonical'),
                    ],
                ],
            ];

            $rows[] = [
                'title' => $release->getTitle(),
                'version' => $release->getVersion() ?: '-',
                'date' => \Drupal::service('date.formatter')->format($release->getReleaseDate(), 'short'),
                'status' => $release->isPublished() ? $this->t('Published') : $this->t('Draft'),
                'operations' => \Drupal::service('renderer')->render($operations),
            ];
        }

        return [
            'add_link' => [
                '#type' => 'link',
                '#title' => $this->t('Add Release'),
                '#url' => Url::fromRoute('changelogify.release_add'),
                '#attributes' => [
                    'class' => ['button', 'button--primary'],
                ],
            ],
            'table' => [
                '#type' => 'table',
                '#header' => $header,
                '#rows' => $rows,
                '#empty' => $this->t('No releases yet.'),
            ],
            'pager' => [
                '#type' => 'pager',
            ],
        ];
    }

}
