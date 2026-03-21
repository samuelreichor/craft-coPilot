<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useModels } from './composables/useModels';
import { useConversations } from './composables/useConversations';
import { useDebugExport } from './composables/useDebugExport';
import { useCommandHandler } from './composables/useCommandHandler';
import type { Attachment, UIMessage } from './types';
import ChatPanel from './components/ChatPanel.vue';

const props = defineProps<{
  contextId: number;
  siteHandle?: string | null;
  executionMode?: string | null;
  currentUserId?: number | null;
  canEditOthers?: boolean;
  canCreateChat?: boolean;
  canDeleteOwn?: boolean;
  canDeleteOthers?: boolean;
}>();

const activeExecutionMode = ref(props.executionMode || 'supervised');

const { models, currentModel, currentProvider, providers, switchProvider } =
  useModels();

const {
  conversations,
  activeConversationId,
  loadConversation,
  deleteConversation,
  refreshConversations,
} = useConversations([], { contextId: props.contextId });

const chatPanel = ref<InstanceType<typeof ChatPanel> | null>(null);
const dropdownWrap = ref<HTMLElement | null>(null);
const { isExporting, exportDebug } = useDebugExport();
const { handleCommand } = useCommandHandler({
  activeConversationId,
  onNewChat: () => newChat(),
  onCompact: (summary) => {
    chatPanel.value?.setMessages([
      { role: 'assistant', content: summary, toolCalls: null, inputTokens: 0, outputTokens: 0 },
    ]);
  },
});
const showDropdown = ref(false);

const isReadonly = computed(() => {
  if (!activeConversationId.value) {
    return props.canCreateChat === false;
  }
  const conv = conversations.value.find((c) => c.id === activeConversationId.value);
  if (!conv || conv.userId === props.currentUserId) return false;
  return !props.canEditOthers;
});

function canDelete(conv: { userId?: number }): boolean {
  const isOwn = conv.userId === props.currentUserId;
  if (isOwn) return props.canDeleteOwn !== false;
  return props.canDeleteOthers === true;
}

function handleClickOutside(e: MouseEvent) {
  if (
    showDropdown.value &&
    dropdownWrap.value &&
    !dropdownWrap.value.contains(e.target as Node)
  ) {
    showDropdown.value = false;
  }
}

async function selectConversation(id: number) {
  showDropdown.value = false;
  if (id === activeConversationId.value) return;
  try {
    const msgs = await loadConversation(id);
    chatPanel.value?.setMessages(msgs);
    chatPanel.value?.setConversationId(id);
  } catch (err) {
    console.error('Failed to load conversation:', err);
  }
}

async function handleDeleteConversation(id: number) {
  try {
    const wasActive = activeConversationId.value === id;
    await deleteConversation(id);
    if (wasActive) {
      chatPanel.value?.clearChat();
    }
  } catch (err) {
    console.error('Failed to delete conversation:', err);
  }
}

function newChat() {
  showDropdown.value = false;
  activeConversationId.value = null;
  chatPanel.value?.clearChat();
}

function onConversationCreated(id: number) {
  activeConversationId.value = id;
  refreshConversations();
}

function focusInput() {
  chatPanel.value?.focusInput();
}

function toggleDropdown() {
  showDropdown.value = !showDropdown.value;
}

function loadHistory() {
  refreshConversations();
}

onMounted(() => {
  loadHistory();
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});

defineExpose({ loadHistory, focusInput });
</script>

<template>
  <div class="co-pilot-slideout-body">
    <div class="co-pilot-slideout-actions">
      <div ref="dropdownWrap" class="co-pilot-slideout-dropdown-wrap">
        <button
          type="button"
          class="btn co-pilot-slideout-dropdown-toggle"
          @click="toggleDropdown"
        >
          <span class="co-pilot-slideout-dropdown-label">
            {{
              activeConversationId
                ? conversations.find((c) => c.id === activeConversationId)
                    ?.title || 'Chat'
                : 'New Chat'
            }}
          </span>
          <span class="co-pilot-slideout-dropdown-caret">
            <svg height="16" width="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M297.4 470.6C309.9 483.1 330.2 483.1 342.7 470.6L534.7 278.6C547.2 266.1 547.2 245.8 534.7 233.3C522.2 220.8 501.9 220.8 489.4 233.3L320 402.7L150.6 233.4C138.1 220.9 117.8 220.9 105.3 233.4C92.8 245.9 92.8 266.2 105.3 278.7L297.3 470.7z"/></svg>
          </span>
        </button>
        <div v-if="showDropdown" class="co-pilot-slideout-dropdown">
          <button
            v-for="conv in conversations"
            :key="conv.id"
            type="button"
            class="co-pilot-slideout-dropdown__item"
            :class="{
              'co-pilot-slideout-dropdown__item--active':
                conv.id === activeConversationId,
            }"
            @click="selectConversation(conv.id)"
          >
            <span class="co-pilot-slideout-dropdown__label">{{ conv.title }}</span>
            <span
              v-if="canDelete(conv)"
              class="co-pilot-slideout-dropdown__delete"
              role="button"
              title="Delete conversation"
              @click.stop="handleDeleteConversation(conv.id)"
            >&times;</span>
          </button>
          <div
            v-if="conversations.length === 0"
            class="co-pilot-slideout-dropdown__empty"
          >
            No conversations yet
          </div>
        </div>
      </div>
      <div v-if="providers.length > 1" class="select">
        <select
          :value="currentProvider"
          @change="switchProvider(($event.target as HTMLSelectElement).value)"
        >
          <option
            v-for="provider in providers"
            :key="provider.handle"
            :value="provider.handle"
          >
            {{ provider.name }}
          </option>
        </select>
      </div>
      <button
        v-if="activeConversationId"
        type="button"
        class="btn"
        :disabled="isExporting"
        title="Export debug log"
        @click="exportDebug(activeConversationId!)"
      >
        {{ isExporting ? '...' : 'Export Debug' }}
      </button>
      <button
        v-if="canCreateChat !== false"
        type="button"
        class="btn submit"
        @click="newChat"
      >
        New Chat +
      </button>
    </div>
    <ChatPanel
      ref="chatPanel"
      context-type="entry"
      :context-id="contextId"
      :site-handle="siteHandle"
      :model="currentModel"
      :models="models"
      :provider="currentProvider"
      :execution-mode="activeExecutionMode"
      :readonly="isReadonly"
      @conversation-created="onConversationCreated"
      @update:model="currentModel = $event"
      @update:execution-mode="activeExecutionMode = $event"
      @command="handleCommand"
    />
  </div>
</template>
