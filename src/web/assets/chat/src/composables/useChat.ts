import { ref, computed } from 'vue';
import { apiPost } from './useCraftApi';
import { useSSE } from './useSSE';
import type {
  UIMessage,
  Attachment,
  AttachmentPayload,
  LiveToolCall,
  ToolCall,
  StreamDoneData,
  ToolStartData,
  ToolEndData,
  SendResponse,
} from '../types';
import {
  buildAttachmentPrefix,
  prepareAttachmentsForSend,
} from '../utils/attachments';

interface UseChatOptions {
  contextType: 'global' | 'entry';
  contextId?: number | null;
  model?: string;
  siteHandle?: string | null;
  executionMode?: string;
  onConversationCreated?: (conversationId: number) => void;
}

export function useChat(options: UseChatOptions) {
  const messages = ref<UIMessage[]>([]);
  const isLoading = ref(false);
  const attachments = ref<Attachment[]>([]);
  const conversationId = ref<number | null>(null);
  const contextId = ref<number | null>(options.contextId ?? null);

  // Streaming state
  const streamingText = ref('');
  const streamingThinking = ref('');
  const liveToolCalls = ref<LiveToolCall[]>([]);
  const isStreaming = ref(false);

  let abortConnection: (() => void) | null = null;

  const { connect } = useSSE();

  const hasStreamingContent = computed(
    () =>
      isStreaming.value &&
      (streamingText.value !== '' ||
        streamingThinking.value !== '' ||
        liveToolCalls.value.length > 0),
  );

  function setMessages(msgs: UIMessage[]) {
    messages.value = msgs;
  }

  function setConversationId(id: number | null) {
    conversationId.value = id;
  }

  function addAttachment(att: Attachment) {
    attachments.value.push(att);
  }

  function removeAttachment(index: number) {
    attachments.value.splice(index, 1);
  }

  function cancel() {
    if (abortConnection) {
      abortConnection();
      abortConnection = null;
    }
    finalizeStream();
  }

  async function sendMessage(text: string, model?: string, executionMode?: string, provider?: string) {
    if (isLoading.value) return;

    // Build full message with attachment prefix
    let fullMessage = text;
    if (attachments.value.length > 0) {
      fullMessage = buildAttachmentPrefix(attachments.value) + text;
    }

    // Prepare structured attachments for backend (reads file content)
    let attachmentPayloads: AttachmentPayload[] = [];
    if (attachments.value.length > 0) {
      attachmentPayloads = await prepareAttachmentsForSend(attachments.value);
    }

    // Add user message to display
    const msgAttachments = attachments.value
      .filter((a) => a.type !== 'file')
      .map((a) => ({ type: a.type, id: a.id, label: a.label }) as Attachment);
    const fileAttachments = attachments.value
      .filter((a) => a.type === 'file')
      .map((a) => ({ type: a.type, label: a.label }) as Attachment);
    const allDisplayAttachments = [...msgAttachments, ...fileAttachments];

    messages.value.push({
      role: 'user',
      content: text,
      attachments: allDisplayAttachments.length > 0 ? allDisplayAttachments : null,
      toolCalls: null,
      inputTokens: 0,
      outputTokens: 0,
    });

    // Set entry context from attachment if we don't have one
    let sendContextId = contextId.value;
    if (!sendContextId) {
      const entryAtt = attachments.value.find((a) => a.type === 'entry');
      if (entryAtt && entryAtt.id) sendContextId = entryAtt.id;
    }

    attachments.value = [];
    isLoading.value = true;

    // Try streaming first, fall back to legacy
    sendStreaming(fullMessage, sendContextId, model ?? options.model, attachmentPayloads, executionMode, provider);
  }

  function sendStreaming(
    message: string,
    sendContextId: number | null | undefined,
    model?: string,
    sendAttachments?: AttachmentPayload[],
    executionMode?: string,
    provider?: string,
  ) {
    isStreaming.value = true;
    streamingText.value = '';
    streamingThinking.value = '';
    liveToolCalls.value = [];

    const connection = connect(
      'co-pilot/chat/send-stream',
      {
        message,
        conversationId: conversationId.value,
        contextId: sendContextId,
        contextType: options.contextType,
        model: model || undefined,
        attachments: sendAttachments?.length ? sendAttachments : undefined,
        siteHandle: options.siteHandle || undefined,
        executionMode: executionMode || undefined,
        provider: provider || undefined,
      },
      {
        onEvent(event) {
          switch (event.type) {
            case 'thinking':
              streamingThinking.value += (event.data.delta as string) || '';
              break;
            case 'text_delta':
              streamingText.value += (event.data.delta as string) || '';
              break;
            case 'tool_start': {
              const ts = event.data as unknown as ToolStartData;
              liveToolCalls.value.push({
                id: ts.id,
                name: ts.name,
                status: 'running',
              });
              break;
            }
            case 'tool_end': {
              const te = event.data as unknown as ToolEndData;
              const tc = liveToolCalls.value.find((t) => t.id === te.id);
              if (tc) {
                tc.status = te.success ? 'success' : 'error';
                tc.entryId = te.entryId;
                tc.entryTitle = te.entryTitle;
                tc.cpEditUrl = te.cpEditUrl;
              }
              break;
            }
            case 'error':
              streamingText.value +=
                '\n\nError: ' + ((event.data.message as string) || 'Unknown error');
              break;
            case 'done': {
              const done = event.data as unknown as StreamDoneData;
              finalizeStream(done);
              break;
            }
          }
        },
        onError(error) {
          // Streaming not available — fall back to legacy
          isStreaming.value = false;
          streamingText.value = '';
          streamingThinking.value = '';
          liveToolCalls.value = [];
          sendLegacy(message, sendContextId, model, sendAttachments, executionMode, provider);
          console.warn('SSE failed, falling back to legacy:', error.message);
        },
        onComplete() {
          // If we didn't get a 'done' event, finalize anyway
          if (isStreaming.value) {
            finalizeStream();
          }
        },
      },
    );

    abortConnection = connection.abort;
  }

  function finalizeStream(done?: StreamDoneData) {
    const text = streamingText.value || 'No response received.';
    const thinking = streamingThinking.value || undefined;
    const toolCalls: ToolCall[] | null =
      liveToolCalls.value.length > 0
        ? liveToolCalls.value.map((tc) => ({
            name: tc.name,
            success: tc.status === 'success',
            entryId: tc.entryId,
            entryTitle: tc.entryTitle,
            cpEditUrl: tc.cpEditUrl,
          }))
        : null;

    messages.value.push({
      role: 'assistant',
      content: text,
      thinking,
      toolCalls,
      inputTokens: done?.inputTokens ?? 0,
      outputTokens: done?.outputTokens ?? 0,
    });

    if (done?.conversationId) {
      conversationId.value = done.conversationId;
      options.onConversationCreated?.(done.conversationId);
    }

    // Reset streaming state
    isStreaming.value = false;
    streamingText.value = '';
    streamingThinking.value = '';
    liveToolCalls.value = [];
    isLoading.value = false;
    abortConnection = null;
  }

  async function sendLegacy(
    message: string,
    sendContextId: number | null | undefined,
    model?: string,
    sendAttachments?: AttachmentPayload[],
    executionMode?: string,
    provider?: string,
  ) {
    try {
      const data = await apiPost<SendResponse>('co-pilot/chat/send', {
        message,
        conversationId: conversationId.value,
        contextId: sendContextId,
        contextType: options.contextType,
        model: model || undefined,
        attachments: sendAttachments?.length ? sendAttachments : undefined,
        siteHandle: options.siteHandle || undefined,
        executionMode: executionMode || undefined,
        provider: provider || undefined,
      });

      messages.value.push({
        role: 'assistant',
        content: data.text || 'No response received.',
        toolCalls: data.toolCalls || null,
        inputTokens: data.inputTokens || 0,
        outputTokens: data.outputTokens || 0,
      });

      if (data.conversationId) {
        conversationId.value = data.conversationId;
        options.onConversationCreated?.(data.conversationId);
      }
    } catch (err: unknown) {
      let errorMsg = 'An error occurred. Please try again.';
      const error = err as { response?: { data?: { message?: string } }; message?: string };
      if (error.response?.data?.message) {
        errorMsg = error.response.data.message;
      } else if (error.message) {
        errorMsg = error.message;
      }
      messages.value.push({
        role: 'assistant',
        content: 'Error: ' + errorMsg,
        toolCalls: null,
        inputTokens: 0,
        outputTokens: 0,
      });
    }

    isLoading.value = false;
  }

  function clearChat() {
    messages.value = [];
    conversationId.value = null;
    attachments.value = [];
    streamingText.value = '';
    streamingThinking.value = '';
    liveToolCalls.value = [];
    isStreaming.value = false;
    isLoading.value = false;
  }

  return {
    messages,
    isLoading,
    attachments,
    conversationId,
    contextId,
    isStreaming,
    streamingText,
    streamingThinking,
    liveToolCalls,
    hasStreamingContent,
    setMessages,
    setConversationId,
    addAttachment,
    removeAttachment,
    sendMessage,
    cancel,
    clearChat,
  };
}
