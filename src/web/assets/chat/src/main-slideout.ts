import './styles/copilot.css';
import { createApp } from 'vue';
import SlideoutApp from './SlideoutApp.vue';

/**
 * Slideout entry point for entry edit pages.
 * Creates a Craft Slideout and mounts the Vue app inside it.
 */
(function () {
  const contextEntryId = window.__coPilotEntryId;
  let contextSiteHandle = window.__coPilotSiteHandle ?? null;

  let slideout: CraftSlideout | null = null;
  let vueInstance: InstanceType<typeof SlideoutApp> | null = null;
  let mountId = 'co-pilot-slideout-vue-' + Date.now();

  function openSlideout() {
    if (!slideout) {
      const $content = $(`<div id="${mountId}"></div>`);
      slideout = new Craft.Slideout($content, {
        containerAttributes: { class: 'co-pilot-craft-slideout' },
      });

      const mountEl = document.getElementById(mountId);
      if (mountEl) {
        const app = createApp(SlideoutApp, {
          contextId: contextEntryId,
          siteHandle: contextSiteHandle,
        });
        vueInstance = app.mount(mountEl) as InstanceType<typeof SlideoutApp>;
      }

      slideout.on('open', () => {
        vueInstance?.loadHistory();
        vueInstance?.focusInput();
      });
    } else {
      slideout.open();
    }
  }

  const btn = document.getElementById('co-pilot-open-chat');
  if (btn) {
    btn.addEventListener('click', () => openSlideout());
  }

  // Expose for external access
  window.coPilotApp = {
    openWithContext(newEntryId: number) {
      const entryChanged = newEntryId !== contextEntryId;

      // Always re-read siteHandle — Craft may have updated it
      contextSiteHandle = window.__coPilotSiteHandle ?? null;

      if (entryChanged && slideout) {
        slideout.close();
        slideout.destroy();
        slideout = null;
        vueInstance = null;
        mountId = 'co-pilot-slideout-vue-' + Date.now();
      }

      // Update for new context
      window.__coPilotEntryId = newEntryId;

      openSlideout();
    },
  };
})();
