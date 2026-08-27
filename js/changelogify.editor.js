(function changelogifyReleaseEditor(Drupal, once) {
  Drupal.behaviors.changelogifyReleasePreview = {
    attach(context, settings) {
      once(
        'changelogify-release-preview',
        'form[data-changelogify-release-editor]',
        context,
      ).forEach((form) => {
        const preview = form.querySelector(
          '[data-changelogify-release-preview]',
        );
        const editOnly = () => [
          ...form.querySelectorAll('.changelogify-release-edit-only'),
        ];
        const controls = [
          ...form.querySelectorAll('[data-changelogify-release-view]'),
        ];
        const labels = settings.changelogifyReleaseEditor?.sectionLabels || {};
        if (!preview || !editOnly().length || !controls.length) return;

        const field = (name) =>
          form.querySelector(`[name="${name}"]`) ||
          form.querySelector(`[name^="${name}["]`);
        const textValue = (name) => field(name)?.value?.trim() || '';
        const formatDate = (dateValue, timeValue) => {
          if (!dateValue) return '';
          try {
            return new Intl.DateTimeFormat(document.documentElement.lang, {
              dateStyle: 'medium',
              ...(timeValue ? { timeStyle: 'short' } : {}),
            }).format(new Date(`${dateValue}T${timeValue || '00:00'}:00`));
          } catch {
            return [dateValue, timeValue].filter(Boolean).join(' ');
          }
        };

        const rebuildPreview = (changedField = null) => {
          const title = preview.querySelector(
            '.changelogify-release-preview__title',
          );
          if (title && changedField === field('title')) {
            title.textContent = textValue('title');
          }

          const dateInput = form.querySelector(
            'input[name*="release_date"][type="date"]',
          );
          const timeInput = form.querySelector(
            'input[name*="release_date"][type="time"]',
          );
          const time = preview.querySelector('.release-meta time');
          if (time && [dateInput, timeInput].includes(changedField)) {
            const dateValue = dateInput?.value || '';
            const timeValue = timeInput?.value || '';
            time.textContent = formatDate(dateValue, timeValue);
            time.dateTime =
              dateValue && timeValue ? `${dateValue}T${timeValue}` : dateValue;
          }

          const meta = preview.querySelector('.release-meta');
          if (changedField === field('version')) {
            const versionValue = textValue('version');
            let version = preview.querySelector('.release-meta .version');
            if (versionValue && meta) {
              if (!version) {
                version = document.createElement('span');
                version.className = 'version';
                meta.append(version);
              }
              version.textContent = Drupal.t('Version @version', {
                '@version': versionValue,
              });
            } else {
              version?.remove();
            }
          }

          const grouped = Object.fromEntries(
            Object.keys(labels).map((section) => [section, []]),
          );
          form
            .querySelectorAll('.changelogify-release-item')
            .forEach((item) => {
              const remove = item.querySelector('input[name$="[remove]"]');
              const text = item.querySelector('textarea[name$="[text]"]');
              const section = item.querySelector('select[name$="[section]"]');
              const value = text?.value?.trim() || '';
              if (remove?.checked || !value || !grouped[section?.value]) return;
              grouped[section.value].push(value);
            });

          const sections = preview.querySelector('.release-sections');
          if (!sections) return;
          sections.replaceChildren();
          Object.entries(grouped).forEach(([key, notes]) => {
            if (!notes.length) return;
            const section = document.createElement('section');
            section.className = `release-section release-section--${key}`;
            const heading = document.createElement('h2');
            heading.textContent = labels[key];
            const list = document.createElement('ul');
            notes.forEach((note) => {
              const item = document.createElement('li');
              item.textContent = note;
              list.append(item);
            });
            section.append(heading, list);
            sections.append(section);
          });
        };

        const setMode = (mode) => {
          const showPreview = mode === 'preview';
          form.dataset.changelogifyReleaseMode = showPreview
            ? 'preview'
            : 'edit';
          preview.hidden = !showPreview;
          editOnly().forEach((element) => {
            element.hidden = showPreview;
          });
          controls.forEach((control) => {
            control.setAttribute(
              'aria-pressed',
              control.dataset.changelogifyReleaseView === mode
                ? 'true'
                : 'false',
            );
          });
          if (showPreview) rebuildPreview();
        };

        controls.forEach((control) => {
          control.addEventListener('click', () => {
            setMode(control.dataset.changelogifyReleaseView);
          });
        });
        form.addEventListener('input', (event) => {
          rebuildPreview(event.target);
        });
        form.addEventListener('change', (event) => {
          rebuildPreview(event.target);
        });
        form.addEventListener('changelogify:release-change', rebuildPreview);
        setMode(form.dataset.changelogifyReleaseMode || 'edit');
      });
    },
  };

  Drupal.behaviors.changelogifyReleaseItemEditor = {
    attach(context) {
      once(
        'changelogify-release-item-editor',
        '.changelogify-release-items',
        context,
      ).forEach((container) => {
        const items = () => [
          ...container.querySelectorAll(':scope > .changelogify-release-item'),
        ];
        const updateOrder = () => {
          items().forEach((item, index) => {
            const input = item.querySelector('input[name$="[order]"]');
            if (input) input.value = index;
          });
          container
            .closest('form')
            ?.dispatchEvent(new CustomEvent('changelogify:release-change'));
        };
        items().forEach((item) => {
          const controls = document.createElement('div');
          controls.className = 'changelogify-release-item__toolbar';
          const drag = document.createElement('button');
          drag.type = 'button';
          drag.className =
            'button button--small changelogify-release-item__drag';
          drag.textContent = Drupal.t('Drag to reorder');
          drag.setAttribute(
            'aria-label',
            Drupal.t('Drag this release note to reorder it'),
          );
          controls.append(drag);
          item.draggable = true;
          let dragAllowed = false;
          drag.addEventListener('pointerdown', () => {
            dragAllowed = true;
          });
          drag.addEventListener('pointerup', () => {
            setTimeout(() => {
              dragAllowed = false;
            });
          });
          item.addEventListener('dragstart', (event) => {
            if (!dragAllowed) {
              event.preventDefault();
              return;
            }
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
          });
          item.addEventListener('dragend', () => {
            dragAllowed = false;
            item.classList.remove('is-dragging');
            updateOrder();
          });
          item.addEventListener('dragover', (event) => {
            event.preventDefault();
            const moving = container.querySelector('.is-dragging');
            if (!moving || moving === item) return;
            const box = item.getBoundingClientRect();
            const after = event.clientY > box.top + box.height / 2;
            container.insertBefore(moving, after ? item.nextSibling : item);
          });
          [
            [Drupal.t('Move up'), -1],
            [Drupal.t('Move down'), 1],
          ].forEach(([label, direction]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button--small';
            button.textContent = label;
            button.setAttribute(
              'aria-label',
              Drupal.t('@action this release note', { '@action': label }),
            );
            button.addEventListener('click', () => {
              const siblings = items();
              const current = siblings.indexOf(item);
              const target = siblings[current + direction];
              if (!target) return;
              if (direction < 0) container.insertBefore(item, target);
              else container.insertBefore(target, item);
              updateOrder();
              item.focus();
            });
            controls.append(button);
          });
          const removeInput = item.querySelector('input[name$="[remove]"]');
          if (removeInput) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button button--small';
            const renderRemovalState = () => {
              const removed = removeInput.checked;
              item.classList.toggle('is-pending-removal', removed);
              remove.textContent = removed
                ? Drupal.t('Undo remove')
                : Drupal.t('Remove');
              remove.setAttribute('aria-pressed', removed ? 'true' : 'false');
            };
            remove.addEventListener('click', () => {
              removeInput.checked = !removeInput.checked;
              renderRemovalState();
              item
                .closest('form')
                ?.dispatchEvent(new CustomEvent('changelogify:release-change'));
              item.focus();
            });
            controls.append(remove);
            renderRemovalState();
          }
          item.prepend(controls);
          item.tabIndex = -1;
        });
      });
    },
  };

  Drupal.behaviors.changelogifyReleaseGenerator = {
    attach(context) {
      once(
        'changelogify-release-generator',
        '.changelogify-release-generator',
        context,
      ).forEach((form) => {
        const search = form.querySelector('.changelogify-candidate-search');
        const source = form.querySelector(
          '.changelogify-candidate-source-filter',
        );
        const rows = [...form.querySelectorAll('.changelogify-change-set-row')];
        const groups = [
          ...form.querySelectorAll('.changelogify-change-set-group'),
        ];
        const commit = form.querySelector('.changelogify-create-draft');
        const aiCommit = form.querySelector('.changelogify-create-ai-draft');

        form.addEventListener('submit', (event) => {
          const { submitter } = event;
          if (submitter !== aiCommit) return;
          aiCommit.setAttribute('aria-busy', 'true');
          // Defer changes to this successful form control until the browser has
          // serialized the triggering button for Drupal's form API.
          window.setTimeout(() => {
            aiCommit.value = Drupal.t('Starting…');
            aiCommit.disabled = true;
            aiCommit.setAttribute('aria-disabled', 'true');
          }, 0);
        });

        const updateActions = () => {
          const selected = rows.some((row) => {
            const checkbox = row.querySelector('input[type="checkbox"]');
            return checkbox?.checked;
          });
          [commit, aiCommit].forEach((button) => {
            if (button) button.disabled = !selected;
          });
        };

        const filter = () => {
          const term = (search?.value || '').trim().toLocaleLowerCase();
          const sourceValue = source?.value || '';
          rows.forEach((row) => {
            const matchesTerm =
              !term || (row.dataset.changelogifySearch || '').includes(term);
            const matchesSource =
              !sourceValue || row.dataset.changelogifySource === sourceValue;
            row.hidden = !(matchesTerm && matchesSource);
          });
          groups.forEach((group) => {
            group.hidden = !group.querySelector(
              '.changelogify-change-set-row:not([hidden])',
            );
          });
        };

        search?.addEventListener('input', filter);
        source?.addEventListener('change', filter);
        rows.forEach((row) => {
          row
            .querySelector('input[type="checkbox"]')
            ?.addEventListener('change', updateActions);
        });
        filter();
        updateActions();
      });
    },
  };
})(Drupal, once);
