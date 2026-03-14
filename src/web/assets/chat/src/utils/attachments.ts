import type { Attachment, AttachmentPayload } from '../types';

const MAX_FILE_SIZE = 100 * 1024; // 100 KB

export const ALLOWED_FILE_EXTENSIONS =
  '.txt,.csv,.json,.xml,.md,.html,.htm,.yaml,.yml,.log';

/**
 * Parses [Entry: Label (ID: 123)] and [Asset: Label (ID: 123)] prefixes from a message.
 * Returns { content, attachments } with the prefix stripped and attachments extracted.
 */
export function parseAttachmentsFromContent(content: string): {
  content: string;
  attachments: Attachment[] | null;
} {
  const pattern = /\[(Entry|Asset):\s+(.+?)\s+\(ID:\s+(\d+)\)\]/g;
  const attachments: Attachment[] = [];
  let match: RegExpExecArray | null;
  while ((match = pattern.exec(content)) !== null) {
    attachments.push({
      type: match[1].toLowerCase() as 'entry' | 'asset',
      label: match[2],
      id: parseInt(match[3], 10),
    });
  }
  const stripped = content.replace(
    /(\[(Entry|Asset):\s+.+?\s+\(ID:\s+\d+\)\]\s*)+\n?/,
    '',
  );
  return {
    content: stripped,
    attachments: attachments.length > 0 ? attachments : null,
  };
}

/**
 * Builds the attachment prefix string to prepend to a user message.
 */
export function buildAttachmentPrefix(attachments: Attachment[]): string {
  const refs = attachments
    .map((a) => {
      if (a.type === 'entry') return `[Entry: ${a.label} (ID: ${a.id})]`;
      if (a.type === 'asset') return `[Asset: ${a.label} (ID: ${a.id})]`;
      if (a.type === 'file') return `[File: ${a.label}]`;
      return '';
    })
    .filter(Boolean);
  return refs.length > 0 ? refs.join(' ') + '\n' : '';
}

/**
 * Reads a File object as text. Returns null if the file is too large.
 */
export function readFileAsText(file: File): Promise<string | null> {
  if (file.size > MAX_FILE_SIZE) {
    return Promise.resolve(null);
  }
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = () => resolve(null);
    reader.readAsText(file);
  });
}

/**
 * Prepares attachments for sending to the backend.
 * Reads file content for file attachments, strips File objects.
 */
export async function prepareAttachmentsForSend(
  attachments: Attachment[],
): Promise<AttachmentPayload[]> {
  const payloads: AttachmentPayload[] = [];

  for (const att of attachments) {
    if (att.type === 'file' && att.file) {
      const content = await readFileAsText(att.file);
      if (content !== null) {
        payloads.push({ type: 'file', label: att.label, content });
      }
    } else if (att.type === 'entry' || att.type === 'asset') {
      payloads.push({ type: att.type, id: att.id, siteId: att.siteId, label: att.label });
    }
  }

  return payloads;
}

/**
 * Validates whether a file is allowed based on extension.
 */
export function isFileAllowed(filename: string): boolean {
  const allowed = ALLOWED_FILE_EXTENSIONS.split(',');
  const ext = '.' + filename.split('.').pop()?.toLowerCase();
  return allowed.includes(ext);
}

/**
 * Returns maximum file size in bytes.
 */
export function getMaxFileSize(): number {
  return MAX_FILE_SIZE;
}
