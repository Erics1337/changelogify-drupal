<?php

declare(strict_types=1);

namespace Drupal\changelogify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\changelogify\ReleaseGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for generating a new release.
 */
class GenerateReleaseForm extends FormBase
{

    /**
     * The release generator.
     */
    protected ReleaseGeneratorInterface $releaseGenerator;

    /**
     * Constructs a GenerateReleaseForm.
     */
    public function __construct(ReleaseGeneratorInterface $release_generator)
    {
        $this->releaseGenerator = $release_generator;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {
        return new static(
            $container->get('changelogify.release_generator')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'changelogify_generate_release_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['mode'] = [
            '#type' => 'radios',
            '#title' => $this->t('Date Range'),
            '#options' => [
                'since_last' => $this->t('Since last release'),
                'custom' => $this->t('Custom date range'),
            ],
            '#default_value' => 'since_last',
            '#required' => TRUE,
        ];

        $form['date_range'] = [
            '#type' => 'container',
            '#states' => [
                'visible' => [
                    ':input[name="mode"]' => ['value' => 'custom'],
                ],
            ],
        ];

        $form['date_range']['start_date'] = [
            '#type' => 'datetime',
            '#title' => $this->t('Start Date'),
            '#date_date_element' => 'date',
            '#date_time_element' => 'none',
        ];

        $form['date_range']['end_date'] = [
            '#type' => 'datetime',
            '#title' => $this->t('End Date'),
            '#date_date_element' => 'date',
            '#date_time_element' => 'none',
        ];

        $form['options'] = [
            '#type' => 'details',
            '#title' => $this->t('Release Options'),
            '#open' => FALSE,
        ];

        $form['options']['title'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Title'),
            '#description' => $this->t('Leave empty to auto-generate based on date.'),
        ];

        $form['options']['version'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Version'),
            '#description' => $this->t('Optional semantic version, e.g. 1.2.0'),
        ];

        $form['actions'] = [
            '#type' => 'actions',
        ];

        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Generate Release'),
            '#button_type' => 'primary',
        ];

        $form['actions']['cancel'] = [
            '#type' => 'link',
            '#title' => $this->t('Cancel'),
            '#url' => Url::fromRoute('changelogify.dashboard'),
            '#attributes' => ['class' => ['button']],
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        if ($form_state->getValue('mode') === 'custom') {
            $start = $form_state->getValue('start_date');
            $end = $form_state->getValue('end_date');

            if (empty($start) || empty($end)) {
                $form_state->setError($form['date_range'], $this->t('Please specify both start and end dates for custom range.'));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $options = [];

        $title = $form_state->getValue('title');
        if (!empty($title)) {
            $options['title'] = $title;
        }

        $version = $form_state->getValue('version');
        if (!empty($version)) {
            $options['version'] = $version;
            $options['label_type'] = 'semantic_version';
        }

        try {
            if ($form_state->getValue('mode') === 'since_last') {
                $release = $this->releaseGenerator->generateReleaseSinceLast($options);
            } else {
                $start = $form_state->getValue('start_date');
                $end = $form_state->getValue('end_date');
                $release = $this->releaseGenerator->generateReleaseFromRange($start, $end, $options);
            }

            $this->messenger()->addStatus($this->t('Draft release "@title" has been created.', [
                '@title' => $release->getTitle(),
            ]));

            $form_state->setRedirectUrl($release->toUrl('edit-form'));
        } catch (\Exception $e) {
            $this->messenger()->addError($this->t('Failed to generate release: @message', [
                '@message' => $e->getMessage(),
            ]));
        }
    }

}
