(function changelogifyAiProgress(Drupal, drupalSettings, once) {
  Drupal.behaviors.changelogifyAiProgress = {
    attach(context) {
      once(
        'changelogify-ai-progress',
        '[data-changelogify-ai-job]',
        context,
      ).forEach((root) => {
        const statusUrl = drupalSettings.changelogifyAiJob?.statusUrl;
        if (!statusUrl || root.dataset.terminal === 'true') return;

        const label = root.querySelector('[data-job-label]');
        const message = root.querySelector('[data-job-message]');
        const progress = root.querySelector('[data-job-progress]');
        const detail = root.querySelector('[data-job-detail]');
        const actions = root.querySelector('[data-job-actions]');
        const stages = [...root.querySelectorAll('[data-job-stage]')];
        const queue = root.querySelector('[data-job-queue]');
        const queueLabel = root.querySelector('[data-job-queue-label]');
        const queueState = root.querySelector('[data-job-queue-state]');
        const queueSummary = root.querySelector('[data-job-queue-summary]');
        const queuedSteps = root.querySelector('[data-job-queued-steps]');
        const lastActivity = root.querySelector('[data-job-last-activity]');
        const nextRun = root.querySelector('[data-job-next-run]');
        const processingLink = root.querySelector('[data-job-processing-link]');
        const delays = [2000, 5000, 15000];
        let attempt = 0;
        let timer;
        let stopped = false;
        let poll;

        const addAction = (text, url, primary = false) => {
          if (!url) return;
          const link = document.createElement('a');
          link.className = primary ? 'button button--primary' : 'button';
          link.href = url;
          link.textContent = text;
          actions.append(link);
        };

        const render = (data) => {
          label.textContent = data.label;
          message.textContent = data.message;
          progress.max = data.progress.total;
          progress.value = data.progress.completed;
          progress.textContent = Drupal.t(
            '@done of @total background steps complete',
            {
              '@done': data.progress.completed,
              '@total': data.progress.total,
            },
          );
          detail.textContent = Drupal.t(
            '@done of @total background steps complete',
            {
              '@done': data.progress.completed,
              '@total': data.progress.total,
            },
          );
          root.dataset.state = data.state;
          const currentStage = ['waiting', 'delayed'].includes(data.state)
            ? 'queued'
            : data.state;
          stages.forEach((stage) => {
            if (stage.dataset.jobStage === currentStage) {
              stage.setAttribute('aria-current', 'step');
            } else {
              stage.removeAttribute('aria-current');
            }
          });
          queue.hidden = !data.queue.visible;
          queueLabel.textContent = data.queue.label;
          queueState.textContent = data.queue.badge;
          queueState.dataset.processorState = data.queue.state;
          queueSummary.textContent = data.queue.summary;
          queuedSteps.textContent = data.queue.queued_steps;
          lastActivity.textContent = data.queue.last_activity;
          nextRun.textContent = data.queue.next_run;
          if (processingLink && data.queue.processing_url) {
            processingLink.href = data.queue.processing_url;
          }
          actions.replaceChildren();
          if (data.release_url)
            addAction(
              Drupal.t('Review unpublished draft'),
              data.release_url,
              true,
            );
          if (data.provenance_url)
            addAction(
              Drupal.t('Review evidence and coverage'),
              data.provenance_url,
            );
          if (data.can_cancel)
            addAction(Drupal.t('Cancel synthesis'), data.cancel_url);
          if (data.state === 'failed')
            addAction(
              Drupal.t('Try again from a fresh preview'),
              data.generate_url,
              true,
            );
          root.dataset.terminal = data.terminal ? 'true' : 'false';
          stopped = data.terminal;
        };

        const schedule = () => {
          if (stopped || document.hidden) return;
          timer = window.setTimeout(
            poll,
            delays[Math.min(attempt, delays.length - 1)],
          );
        };

        poll = async () => {
          if (stopped || document.hidden) return;
          try {
            const response = await fetch(statusUrl, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
              cache: 'no-store',
            });
            if (!response.ok)
              throw new Error(`Status request failed: ${response.status}`);
            render(await response.json());
            attempt += 1;
          } catch (error) {
            message.textContent = Drupal.t(
              'The status could not be refreshed. Changelogify will try again automatically.',
            );
            attempt = Math.max(attempt, 2);
          }
          schedule();
        };

        document.addEventListener('visibilitychange', () => {
          window.clearTimeout(timer);
          if (!document.hidden && !stopped) poll();
        });
        schedule();
      });
    },
  };
})(Drupal, drupalSettings, once);
