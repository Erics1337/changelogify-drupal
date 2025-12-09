<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for editing releases.
 */
class ReleaseForm extends ContentEntityForm
{

    /**
     * {@inheritdoc}
     */
    public function form(array $form, FormStateInterface $form_state): array
    {
        $form = parent::form($form, $form_state);

        /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
        $release = $this->entity;

        // Add sections editing.
        $form['sections_wrapper'] = [
            '#type' => 'details',
            '#title' => $this->t('Release Sections'),
            '#open' => TRUE,
            '#weight' => 5,
            '#tree' => TRUE,
        ];

        $sections = $release->getSections();
        $section_labels = [
            'added' => $this->t('Added'),
            'changed' => $this->t('Changed'),
            'fixed' => $this->t('Fixed'),
            'removed' => $this->t('Removed'),
            'security' => $this->t('Security'),
            'other' => $this->t('Other'),
        ];

        foreach ($section_labels as $key => $label) {
            $items = $sections[$key] ?? [];

            $form['sections_wrapper']['section_' . $key] = [
                '#type' => 'details',
                '#title' => $label . ' (' . count($items) . ')',
                '#open' => !empty($items),
            ];

            $form['sections_wrapper']['section_' . $key]['items'] = [
                '#type' => 'textarea',
                '#title' => $this->t('Items'),
                '#description' => $this->t('One item per line.'),
                '#default_value' => $this->itemsToText($items),
                '#rows' => max(3, count($items)),
            ];
        }

        return $form;
    }

    /**
     * Converts items array to text.
     */
    protected function itemsToText(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = $item['text'] ?? '';
        }
        return implode("\n", $lines);
    }

    /**
     * Converts text to items array.
     */
    protected function textToItems(string $text): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'id' => \Drupal::service('uuid')->generate(),
                'text' => $line,
                'event_ids' => [],
            ];
        }
        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $form, FormStateInterface $form_state): int
    {
        /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
        $release = $this->entity;

        // Build sections from form values.
        $sections = [];
        $section_keys = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];

        foreach ($section_keys as $key) {
            $text = $form_state->getValue(['sections_wrapper', 'section_' . $key, 'items'], '');
            $sections[$key] = $this->textToItems($text);
        }

        $release->setSections($sections);

        $status = parent::save($form, $form_state);

        $message = $status === SAVED_NEW
            ? $this->t('Release "@title" has been created.', ['@title' => $release->getTitle()])
            : $this->t('Release "@title" has been updated.', ['@title' => $release->getTitle()]);

        $this->messenger()->addStatus($message);

        $form_state->setRedirectUrl($release->toUrl('collection'));

        return $status;
    }

}
