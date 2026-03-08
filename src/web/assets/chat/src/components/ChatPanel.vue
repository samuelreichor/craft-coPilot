<script setup lang="ts">
import { ref } from 'vue';
import type { ChatPanelProps, UIMessage } from '../types';
import { useChat } from '../composables/useChat';
import MessageList from './MessageList.vue';
import ChatInput from './ChatInput.vue';

const props = withDefaults(defineProps<ChatPanelProps & {
  model?: string;
  siteHandle?: string | null;
  models?: string[];
  executionMode?: string;
  provider?: string;
}>(), {
  contextId: null,
  initialConversationId: null,
  compact: false,
  model: '',
  siteHandle: null,
  models: () => [],
  executionMode: 'supervised',
  provider: '',
});

const emit = defineEmits<{
  'conversation-created': [id: number];
  'update:model': [value: string];
  'update:executionMode': [value: string];
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

function handleSend(text: string) {
  chat.sendMessage(text, props.model || undefined, props.executionMode || undefined, props.provider || undefined);
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
      :compact="compact"
      :is-streaming="chat.isStreaming.value"
      :streaming-text="chat.streamingText.value"
      :streaming-thinking="chat.streamingThinking.value"
      :live-tool-calls="chat.liveToolCalls.value"
      @suggest="handleSend"
    />
    <ChatInput
      ref="chatInput"
      :is-loading="chat.isLoading.value"
      :is-streaming="chat.isStreaming.value"
      :attachments="chat.attachments.value"
      :compact="compact"
      :models="models"
      :current-model="model"
      :execution-mode="executionMode"
      @send="handleSend"
      @cancel="chat.cancel()"
      @add-attachment="chat.addAttachment($event)"
      @remove-attachment="chat.removeAttachment($event)"
      @update:current-model="$emit('update:model', $event)"
      @update:execution-mode="$emit('update:executionMode', $event)"
    />
  </div>
</template>
