<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { apiPost } from './composables/useCraftApi';
import { useDebugExport } from './composables/useDebugExport';
import { useCommandHandler } from './composables/useCommandHandler';
import type { ConversationSummary, UIMessage } from './types';
import ChatPanel from './components/ChatPanel.vue';

const props = defineProps<{
  contextId: number;
}>();

const chatPanel = ref<InstanceType<typeof ChatPanel> | null>(null);
const dropdownWrap = ref<HTMLElement | null>(null);
const { isExporting, exportDebug } = useDebugExport();
const conversations = ref<ConversationSummary[]>([]);
const activeConversationId = ref<number | null>(null);
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
const historyLoaded = ref(false);

function handleClickOutside(e: MouseEvent) {
  if (
    showDropdown.value &&
    dropdownWrap.value &&
    !dropdownWrap.value.contains(e.target as Node)
  ) {
    showDropdown.value = false;
  }
}

async function fetchConversations() {
  try {
    const data = await apiPost<ConversationSummary[]>(
      'co-pilot/chat/get-entry-conversations',
      { contextId: props.contextId },
    );
    conversations.value = data || [];
  } catch {
    conversations.value = [];
  }
}

async function loadConversation(id: number) {
  try {
    const data = await apiPost<{
      id: number | null;
      messages: Array<{ role: string; content: string }>;
    }>('co-pilot/chat/load-conversation', { id });

    if (data.id) {
      activeConversationId.value = data.id;
      chatPanel.value?.setConversationId(data.id);
      chatPanel.value?.setMessages(
        (data.messages || [])
          .filter((m) => m.role === 'user' || m.role === 'assistant')
          .map(
            (m) =>
              ({
                role: m.role as 'user' | 'assistant',
                content:
                  typeof m.content === 'string'
                    ? m.content
                    : JSON.stringify(m.content),
                toolCalls: null,
                inputTokens: 0,
                outputTokens: 0,
              }) as UIMessage,
          ),
      );
    }
  } catch (err) {
    console.error('Failed to load conversation:', err);
  }
}

async function loadHistory() {
  if (historyLoaded.value || !props.contextId) return;
  historyLoaded.value = true;

  await fetchConversations();
}

function selectConversation(id: number) {
  showDropdown.value = false;
  if (id === activeConversationId.value) return;
  loadConversation(id);
}

function newChat() {
  showDropdown.value = false;
  activeConversationId.value = null;
  chatPanel.value?.clearChat();
}

function onConversationCreated(id: number) {
  activeConversationId.value = id;
  fetchConversations();
}

function focusInput() {
  chatPanel.value?.focusInput();
}

function toggleDropdown() {
  showDropdown.value = !showDropdown.value;
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
          class="btn small co-pilot-slideout-dropdown-toggle"
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
          <span class="co-pilot-slideout-dropdown-caret">&#9662;</span>
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
            {{ conv.title }}
          </button>
          <div
            v-if="conversations.length === 0"
            class="co-pilot-slideout-dropdown__empty"
          >
            No conversations yet
          </div>
        </div>
      </div>
      <button type="button" class="btn small" @click="newChat">+ New</button>
      <button
        v-if="activeConversationId"
        type="button"
        class="btn small"
        :disabled="isExporting"
        title="Export debug log"
        @click="exportDebug(activeConversationId!)"
      >
        {{ isExporting ? '...' : 'Export Debug' }}
      </button>
    </div>
    <ChatPanel
      ref="chatPanel"
      context-type="entry"
      :context-id="contextId"
      @conversation-created="onConversationCreated"
      @command="handleCommand"
    />
  </div>
</template>
