export interface Attachment {
  type: 'entry' | 'asset' | 'file';
  id?: number;
  label: string;
  file?: File;
  content?: string;
}

export interface AttachmentPayload {
  type: 'entry' | 'asset' | 'file';
  id?: number;
  label: string;
  content?: string;
}

export interface ToolCall {
  name: string;
  success: boolean;
  entryId?: number | null;
  entryTitle?: string | null;
  cpEditUrl?: string | null;
}

export interface UIMessage {
  role: 'user' | 'assistant';
  content: string;
  attachments?: Attachment[] | null;
  toolCalls?: ToolCall[] | null;
  inputTokens: number;
  outputTokens: number;
  /** Streaming-specific fields */
  thinking?: string;
  isStreaming?: boolean;
}

export interface ConversationSummary {
  id: number;
  title: string;
  dateUpdated: string;
}

export interface ChatPanelProps {
  contextType: 'global' | 'entry';
  contextId?: number | null;
  initialConversationId?: number | null;
  compact?: boolean;
}

/** SSE event types from the backend */
export type StreamEventType =
  | 'thinking'
  | 'text_delta'
  | 'tool_start'
  | 'tool_end'
  | 'error'
  | 'done';

export interface StreamEvent {
  type: StreamEventType;
  data: Record<string, unknown>;
}

export interface StreamDoneData {
  conversationId: number | null;
  inputTokens: number;
  outputTokens: number;
}

export interface ToolStartData {
  id: string;
  name: string;
}

export interface ToolEndData {
  id: string;
  name: string;
  success: boolean;
  entryId?: number | null;
  entryTitle?: string | null;
  cpEditUrl?: string | null;
}

export interface LiveToolCall {
  id: string;
  name: string;
  status: 'running' | 'success' | 'error';
  entryId?: number | null;
  entryTitle?: string | null;
  cpEditUrl?: string | null;
}

/** Non-streaming send response (legacy) */
export interface SendResponse {
  text: string | null;
  toolCalls?: ToolCall[] | null;
  inputTokens: number;
  outputTokens: number;
  conversationId: number | null;
}

export interface ModelsResponse {
  provider: string;
  providerName: string;
  models: string[];
  currentModel: string | null;
}

export interface ConversationDetail {
  id: number;
  title: string;
  contextId?: number | null;
  messages: Array<{
    role: string;
    content: string;
    toolCallId?: string | null;
    toolName?: string | null;
    toolCalls?: ToolCall[] | null;
  }>;
}