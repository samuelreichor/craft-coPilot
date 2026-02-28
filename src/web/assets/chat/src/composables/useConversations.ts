import { ref } from 'vue';
import { apiPost } from './useCraftApi';
import type {
  ConversationSummary,
  ConversationDetail,
  ToolCall,
  UIMessage,
} from '../types';
import { parseAttachmentsFromContent } from '../utils/attachments';

export function useConversations(
  initialConversations: ConversationSummary[] = [],
) {
  const conversations = ref<ConversationSummary[]>(initialConversations);
  const activeConversationId = ref<number | null>(null);

  async function loadConversation(id: number): Promise<UIMessage[]> {
    const data = await apiPost<ConversationDetail>(
      'co-pilot/chat/load-conversation',
      { id },
    );
    activeConversationId.value = data.id;

    return (data.messages || [])
      .filter((m) => m.role === 'user' || m.role === 'assistant')
      .map((m) => {
        const raw =
          typeof m.content === 'string' ? m.content : JSON.stringify(m.content);
        if (m.role === 'user') {
          const parsed = parseAttachmentsFromContent(raw);
          return {
            role: 'user' as const,
            content: parsed.content,
            attachments: parsed.attachments,
            toolCalls: null,
            inputTokens: 0,
            outputTokens: 0,
          };
        }
        return {
          role: 'assistant' as const,
          content: raw,
          toolCalls: m.toolCalls ?? null,
          inputTokens: 0,
          outputTokens: 0,
        };
      });
  }

  async function loadEntryConversation(
    contextId: number,
  ): Promise<{ conversationId: number | null; messages: UIMessage[] }> {
    const data = await apiPost<{
      id: number | null;
      messages: Array<{
        role: string;
        content: string;
        toolCalls?: ToolCall[] | null;
      }>;
    }>('co-pilot/chat/load-entry-conversation', { contextId });

    const messages: UIMessage[] = (data.messages || [])
      .filter((m) => m.role === 'user' || m.role === 'assistant')
      .map((m) => ({
        role: m.role as 'user' | 'assistant',
        content:
          typeof m.content === 'string' ? m.content : JSON.stringify(m.content),
        toolCalls: m.role === 'assistant' ? (m.toolCalls ?? null) : null,
        inputTokens: 0,
        outputTokens: 0,
      }));

    return { conversationId: data.id, messages };
  }

  async function deleteConversation(id: number): Promise<void> {
    await apiPost('co-pilot/chat/delete-conversation', { id });
    conversations.value = conversations.value.filter((c) => c.id !== id);
    if (activeConversationId.value === id) {
      activeConversationId.value = null;
    }
  }

  async function refreshConversations(): Promise<void> {
    try {
      const data = await apiPost<ConversationSummary[]>(
        'co-pilot/chat/get-conversations',
      );
      conversations.value = data;
    } catch {
      // Sidebar refresh is non-critical
    }
  }

  return {
    conversations,
    activeConversationId,
    loadConversation,
    loadEntryConversation,
    deleteConversation,
    refreshConversations,
  };
}
