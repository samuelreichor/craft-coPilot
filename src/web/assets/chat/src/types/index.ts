export interface Attachment {
  type: 'entry' | 'asset' | 'file';
  id?: number;
  siteId?: number;
  label: string;
  file?: File;
  content?: string;
}

export interface AttachmentPayload {
  type: 'entry' | 'asset' | 'file';
  id?: number;
  siteId?: number;
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
}

export interface ConversationSummary {
  id: number;
  title: string;
  dateUpdated: string;
  userId?: number;
}

export interface ChatPanelProps {
  contextType: 'global' | 'entry';
  contextId?: number | null;
  initialConversationId?: number | null;
  compact?: boolean;
}

/** SSE event types from the backend */
export type StreamEventType =
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

export interface ProviderInfo {
  handle: string;
  name: string;
  models: string[];
  defaultModel: string | null;
}

export interface CommandParam {
  type: 'entry' | 'asset' | 'file' | 'text';
  label: string;
}

export interface SlashCommand {
  name: string;
  description: string;
  prompt?: string;
  param?: CommandParam;
}

export interface ModelsResponse {
  provider: string;
  providerName: string;
  models: string[];
  currentModel: string | null;
  providers: ProviderInfo[];
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