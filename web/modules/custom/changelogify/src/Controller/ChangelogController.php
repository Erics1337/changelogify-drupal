<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;

/**
 * Controller for public changelog pages.
 */
class ChangelogController extends ControllerBase
{

    /**
     * Displays the public changelog listing.
     */
    public function listing(): array
    {
        $storage = $this->entityTypeManager()->getStorage('changelogify_release');

        $release_ids = $storage->getQuery()
            ->accessCheck(TRUE)
            ->condition('status', TRUE)
            ->sort('release_date', 'DESC')
            ->pager(10)
            ->execute();

        $releases = $storage->loadMultiple($release_ids);

        $items = [];
        foreach ($releases as $release) {
            $sections = $release->getSections();
            $excerpt = $this->buildExcerpt($sections);

            $items[] = [
                'release' => $release,
                'date' => \Drupal::service('date.formatter')->format($release->getReleaseDate(), 'medium'),
                'excerpt' => $excerpt,
            ];
        }

        return [
            '#theme' => 'changelogify_release_list',
            '#releases' => $items,
            'pager' => [
                '#type' => 'pager',
            ],
        ];
    }

    /**
     * Displays a single release.
     */
    public function view(ChangelogifyReleaseInterface $changelogify_release): array
    {
        $sections = $changelogify_release->getSections();
        $section_labels = [
            'added' => $this->t('Added'),
            'changed' => $this->t('Changed'),
            'fixed' => $this->t('Fixed'),
            'removed' => $this->t('Removed'),
            'security' => $this->t('Security'),
            'other' => $this->t('Other'),
        ];

        $rendered_sections = [];
        foreach ($sections as $key => $items) {
            if (!empty($items)) {
                $rendered_sections[$key] = [
                    'label' => $section_labels[$key] ?? ucfirst($key),
                    'items' => $items,
                ];
            }
        }

        return [
            '#theme' => 'changelogify_release',
            '#release' => $changelogify_release,
            '#sections' => $rendered_sections,
        ];
    }

    /**
     * Title callback for release view.
     */
    public function title(ChangelogifyReleaseInterface $changelogify_release): string
    {
        return $changelogify_release->getTitle();
    }

    /**
     * Builds an excerpt from sections.
     */
    protected function buildExcerpt(array $sections): string
    {
        $items = [];
        foreach ($sections as $section_items) {
            foreach ($section_items as $item) {
                $items[] = $item['text'] ?? '';
                if (count($items) >= 2) {
                    break 2;
                }
            }
        }

        if (empty($items)) {
            return '';
        }

        return implode(' • ', array_filter($items));
    }

}
