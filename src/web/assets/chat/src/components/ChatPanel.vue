<script setup lang="ts">
import { ref } from 'vue';
import type { Attachment, ChatPanelProps, PendingToolCall, UIMessage } from '../types';
import { useChat } from '../composables/useChat';
import MessageList from './MessageList.vue';
import ChatInput from './ChatInput.vue';

const props = withDefaults(defineProps<ChatPanelProps & {
  model?: string;
  siteHandle?: string | null;
  models?: string[];
  executionMode?: string;
  provider?: string;
  readonly?: boolean;
  canChangeExecutionMode?: boolean;
  canChangeModel?: boolean;
}>(), {
  contextId: null,
  initialConversationId: null,
  model: '',
  siteHandle: null,
  models: () => [],
  executionMode: 'supervised',
  provider: '',
  readonly: false,
  canChangeExecutionMode: true,
  canChangeModel: true,
});

const emit = defineEmits<{
  'conversation-created': [id: number];
  'update:model': [value: string];
  'update:executionMode': [value: string];
  command: [name: string];
}>();

const chatInput = ref<InstanceType<typeof ChatInput> | null>(null);

const chat = useChat({
  contextType: props.contextType,
  contextId: props.contextId,
  siteHandle: props.siteHandle,
  onConversationCreated(id) {
    emit('conversation-created', id);
  },
});

function handleSend(text: string, extraAttachments?: Attachment[]) {
  if (extraAttachments) {
    for (const att of extraAttachments) {
      chat.addAttachment(att);
    }
  }
  chat.sendMessage(text, props.model || undefined, props.executionMode || undefined, props.provider || undefined);
}

function handleApproval(approved: boolean) {
  chat.resolveApproval(
    approved,
    props.model || undefined,
    props.executionMode || undefined,
    props.provider || undefined,
  );
}

function setPendingApproval(pending: PendingToolCall[] | null) {
  chat.setPendingApproval(pending);
}

function setMessages(msgs: UIMessage[]) {
  chat.setMessages(msgs);
}

function setConversationId(id: number | null) {
  chat.setConversationId(id);
}

function clearChat() {
  chat.clearChat();
}

function focusInput() {
  setTimeout(() => chatInput.value?.focus(), 100);
}


defineExpose({
  messages: chat.messages,
  conversationId: chat.conversationId,
  setMessages,
  setConversationId,
  setPendingApproval,
  clearChat,
  focusInput,
  isLoading: chat.isLoading,
});
</script>

<template>
  <div class="co-pilot-chat-main">
    <MessageList
      :messages="chat.messages.value"
      :is-loading="chat.isLoading.value"
      :is-streaming="chat.isStreaming.value"
      :streaming-text="chat.streamingText.value"
      :live-tool-calls="chat.liveToolCalls.value"
      :pending-approval="chat.pendingApproval.value"
      @suggest="handleSend"
      @approve="handleApproval(true)"
      @reject="handleApproval(false)"
    />
    <ChatInput
      ref="chatInput"
      :is-loading="chat.isLoading.value"
      :is-streaming="chat.isStreaming.value"
      :attachments="chat.attachments.value"
      :models="models"
      :current-model="model"
      :execution-mode="executionMode"
      :readonly="readonly"
      :can-change-execution-mode="canChangeExecutionMode"
      :can-change-model="canChangeModel"
      @send="(text, atts) => handleSend(text, atts)"
      @cancel="chat.cancel()"
      @add-attachment="chat.addAttachment($event)"
      @remove-attachment="chat.removeAttachment($event)"
      @update:current-model="$emit('update:model', $event)"
      @update:execution-mode="$emit('update:executionMode', $event)"
      @command="$emit('command', $event)"
    />
  </div>
</template>
