(function changelogifyAiProgress(Drupal, drupalSettings, once) {
  Drupal.behaviors.changelogifyAiProgress = {
    attach(context) {
      once('changelogify-ai-progress', '[data-changelogify-ai-job]', context).forEach((root) => {
        const statusUrl = drupalSettings.changelogifyAiJob?.statusUrl;
        if (!statusUrl || root.dataset.terminal === 'true') return;

        const label = root.querySelector('[data-job-label]');
        const message = root.querySelector('[data-job-message]');
        const progress = root.querySelector('[data-job-progress]');
        const detail = root.querySelector('[data-job-detail]');
        const actions = root.querySelector('[data-job-actions]');
        const delays = [2000, 5000, 15000];
        let attempt = 0;
        let timer;
        let stopped = false;

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
          progress.textContent = Drupal.t('@done of @total background steps complete', {
            '@done': data.progress.completed,
            '@total': data.progress.total,
          });
          detail.textContent = Drupal.t('@done of @total background steps complete', {
            '@done': data.progress.completed,
            '@total': data.progress.total,
          });
          actions.replaceChildren();
          if (data.release_url) addAction(Drupal.t('Review unpublished draft'), data.release_url, true);
          if (data.provenance_url) addAction(Drupal.t('Review evidence and coverage'), data.provenance_url);
          if (data.can_cancel) addAction(Drupal.t('Cancel synthesis'), data.cancel_url);
          if (data.state === 'failed') addAction(Drupal.t('Try again from a fresh preview'), data.generate_url, true);
          root.dataset.terminal = data.terminal ? 'true' : 'false';
          stopped = data.terminal;
        };

        const schedule = () => {
          if (stopped || document.hidden) return;
          timer = window.setTimeout(poll, delays[Math.min(attempt, delays.length - 1)]);
        };

        const poll = async () => {
          if (stopped || document.hidden) return;
          try {
            const response = await fetch(statusUrl, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
              cache: 'no-store',
            });
            if (!response.ok) throw new Error(`Status request failed: ${response.status}`);
            render(await response.json());
            attempt += 1;
          } catch (error) {
            message.textContent = Drupal.t('The status could not be refreshed. Changelogify will try again automatically.');
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
