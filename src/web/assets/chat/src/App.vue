<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useModels } from './composables/useModels';
import { useConversations } from './composables/useConversations';
import { useDebugExport } from './composables/useDebugExport';
import { useCommandHandler } from './composables/useCommandHandler';
import ConversationSidebar from './components/ConversationSidebar.vue';
import HeaderActions from './components/HeaderActions.vue';
import ChatPanel from './components/ChatPanel.vue';

const init = window.__COPILOT_INIT__ || {};

const { models, currentModel, currentProvider, providers, switchProvider } =
  useModels();
const executionMode = ref(init.executionMode || 'supervised');
const {
  conversations,
  activeConversationId,
  loadConversation,
  deleteConversation,
  refreshConversations,
} = useConversations(init.conversations || []);

const chatPanel = ref<InstanceType<typeof ChatPanel> | null>(null);
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

const isReadonly = computed(() => {
  // No active conversation — check createChat permission
  if (!activeConversationId.value) {
    return init.permissions?.createChat === false;
  }
  // Own conversation — always editable
  const conv = conversations.value.find((c) => c.id === activeConversationId.value);
  if (!conv || conv.userId === init.currentUserId) return false;
  // Other user's conversation — check editOtherUsersChats
  return !init.permissions?.editOtherUsersChats;
});

function updateUrl(conversationId: number | null) {
  const path = conversationId ? `co-pilot/${conversationId}` : 'co-pilot';
  history.replaceState(null, '', Craft.getCpUrl(path));
}

async function selectConversation(id: number) {
  if (id === activeConversationId.value) return;
  try {
    const msgs = await loadConversation(id);
    chatPanel.value?.setMessages(msgs);
    chatPanel.value?.setConversationId(id);
    updateUrl(id);
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
      updateUrl(null);
    }
  } catch (err) {
    console.error('Failed to delete conversation:', err);
  }
}

function newChat() {
  activeConversationId.value = null;
  chatPanel.value?.clearChat();
  updateUrl(null);
}

function onConversationCreated(id: number) {
  activeConversationId.value = id;
  refreshConversations();
  updateUrl(id);
}

function handleExportDebug() {
  if (activeConversationId.value) {
    exportDebug(activeConversationId.value);
  }
}

onMounted(() => {
  if (init.activeConversationId) {
    selectConversation(init.activeConversationId);
  }
});
</script>

<template>
  <Teleport to="#co-pilot-action-btns">
    <HeaderActions
      :conversation-id="activeConversationId"
      :is-exporting="isExporting"
      :providers="providers"
      :current-provider="currentProvider"
      :can-create-chat="init.permissions?.createChat"
      :can-change-provider="init.permissions?.changeProvider !== false"
      @new-chat="newChat"
      @export-debug="handleExportDebug"
      @update:current-provider="switchProvider($event)"
    />
  </Teleport>
  <Teleport to="#co-pilot-sidebar-mount">
    <ConversationSidebar
      :conversations="conversations"
      :active-id="activeConversationId"
      :current-user-id="init.currentUserId"
      :can-delete-own="init.permissions?.deleteChat"
      :can-delete-others="init.permissions?.deleteOtherUsersChats"
      @select="selectConversation"
      @delete="handleDeleteConversation"
    />
  </Teleport>
  <ChatPanel
    ref="chatPanel"
    context-type="global"
    :context-id="init.contextId"
    :model="currentModel"
    :models="models"
    :provider="currentProvider"
    :execution-mode="executionMode"
    :site-handle="init.siteHandle"
    :readonly="isReadonly"
    :can-change-execution-mode="init.permissions?.changeExecutionMode !== false"
    :can-change-model="init.permissions?.changeModel !== false"
    @conversation-created="onConversationCreated"
    @update:model="currentModel = $event"
    @update:execution-mode="executionMode = $event"
    @command="handleCommand"
  />
</template>
