(function changelogifyReleaseEditor(Drupal, once) {
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
        };
        items().forEach((item) => {
          const controls = document.createElement('div');
          controls.className = 'changelogify-release-item__toolbar';
          const drag = document.createElement('button');
          drag.type = 'button';
          drag.className = 'button button--small changelogify-release-item__drag';
          drag.textContent = Drupal.t('Drag to reorder');
          drag.setAttribute('aria-label', Drupal.t('Drag this release note to reorder it'));
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
            button.setAttribute('aria-label', Drupal.t('@action this release note', { '@action': label }));
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
              remove.textContent = removed ? Drupal.t('Undo remove') : Drupal.t('Remove');
              remove.setAttribute('aria-pressed', removed ? 'true' : 'false');
            };
            remove.addEventListener('click', () => {
              removeInput.checked = !removeInput.checked;
              renderRemovalState();
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
})(Drupal, once);
