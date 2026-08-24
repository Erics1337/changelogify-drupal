(function (Drupal, once) {
  Drupal.behaviors.changelogifyReleaseItemEditor = {
    attach(context) {
      once(
        "changelogify-release-item-editor",
        ".changelogify-release-items",
        context,
      ).forEach((container) => {
        const items = () => [
          ...container.querySelectorAll(":scope > .changelogify-release-item"),
        ];
        const updateOrder = () => {
          items().forEach((item, index) => {
            const input = item.querySelector('input[name$="[order]"]');
            if (input) input.value = index;
          });
        };
        items().forEach((item) => {
          const controls = document.createElement("div");
          controls.className = "changelogify-release-item__move";
          [
            [Drupal.t("Move up"), -1],
            [Drupal.t("Move down"), 1],
          ].forEach(([label, direction]) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "button button--small";
            button.textContent = label;
            button.addEventListener("click", () => {
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
          item.prepend(controls);
          item.tabIndex = -1;
        });
      });
    },
  };
})(Drupal, once);
