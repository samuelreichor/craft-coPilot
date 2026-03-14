/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<object, object, unknown>;
  export default component;
}

interface CraftStatic {
  sendActionRequest(method: string, action: string, options?: { data?: unknown }): Promise<{ data: unknown }>;
  csrfTokenValue: string;
  csrfTokenName: string;
  actionUrl: string;
  getActionUrl(action: string, params?: Record<string, string>): string;
  getCpUrl(path: string, params?: Record<string, string>): string;
  createElementSelectorModal(
    elementType: string,
    options: {
      multiSelect?: boolean;
      onSelect?: (elements: Array<{ id: number; label: string; siteId?: number }>) => void;
    },
  ): void;
  Slideout: new (
    $content: JQuery,
    options?: { containerAttributes?: { class?: string } },
  ) => CraftSlideout;
}

interface CraftSlideout {
  open(): void;
  close(): void;
  destroy(): void;
  on(event: string, callback: () => void): void;
}

interface Window {
  Craft: CraftStatic;
  __COPILOT_INIT__?: {
    conversations?: Array<{ id: number; title: string; dateUpdated: string }>;
    contextId?: number | null;
    activeConversationId?: number | null;
    siteHandle?: string | null;
  };
  __coPilotEntryId?: number | null;
  __coPilotSiteHandle?: string | null;
  coPilotApp?: {
    openWithContext: (entryId: number) => void;
  };
}

declare const Craft: CraftStatic;
declare const $: JQueryStatic;
