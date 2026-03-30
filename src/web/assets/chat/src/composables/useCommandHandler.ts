import type { Ref } from 'vue';

const GITHUB_ISSUES_URL = 'https://github.com/samuelreichor/craft-co-pilot/issues/new';
const MAX_URL_LENGTH = 8192;

interface CommandHandlerOptions {
  activeConversationId: Ref<number | null>;
  onNewChat?: () => void;
  onCompact?: (summary: string) => void;
}

/**
 * Handles built-in commands that require frontend-specific logic.
 * Prompt-based commands (registered via PHP events) are handled
 * directly in ChatInput by sending the prompt as a message.
 */
export function useCommandHandler({ activeConversationId, onNewChat, onCompact }: CommandHandlerOptions) {
  async function handleCommand(name: string) {
    if (name === 'new-chat') {
      onNewChat?.();
      return;
    }

    if (name === 'report-bug') {
      await sendDebugLog();
      return;
    }

    if (name === 'compact') {
      await compactConversation();
    }
  }

  async function compactConversation() {
    if (!activeConversationId.value) {
      Craft.cp.displayError('No active conversation to compact.');
      return;
    }

    try {
      const response = await Craft.sendActionRequest(
        'POST',
        'co-pilot/chat/compact-conversation',
        { data: { id: activeConversationId.value } },
      );

      if (!response.data.success) {
        Craft.cp.displayError(response.data.error || 'Failed to compact conversation.');
        return;
      }

      onCompact?.(response.data.summary);
      Craft.cp.displayNotice('Conversation compacted.');
    } catch (err) {
      console.error('Failed to compact conversation:', err);
      Craft.cp.displayError('Failed to compact conversation.');
    }
  }

  async function sendDebugLog() {
    if (!activeConversationId.value) {
      Craft.cp.displayError('No active conversation to export.');
      return;
    }

    try {
      const response = await Craft.sendActionRequest(
        'POST',
        'co-pilot/chat/export-debug',
        { data: { id: activeConversationId.value } },
      );

      const meta = response.data.meta || {};
      const json = JSON.stringify(response.data, null, 2);
      const debugLog =
        '<details>\n<summary>Debug Log</summary>\n\n```json\n' +
        json +
        '\n```\n\n</details>';

      const params = new URLSearchParams({
        template: 'bug-report.yaml',
        title: `Bug Report – Conversation #${activeConversationId.value}`,

        'craft-version': meta.craftVersion || '',
        'copilot-version': meta.copilotVersion || '',
        'php-version': meta.phpVersion || '',
        'debug-log': debugLog,
      });

      const issueUrl = `${GITHUB_ISSUES_URL}?${params.toString()}`;

      if (issueUrl.length <= MAX_URL_LENGTH) {
        window.open(issueUrl, '_blank');
      } else {
        await navigator.clipboard.writeText(debugLog);

        const fallbackParams = new URLSearchParams({
          template: 'bug-report.yaml',
          title: `Bug Report – Conversation #${activeConversationId.value}`,

          'craft-version': meta.craftVersion || '',
          'copilot-version': meta.copilotVersion || '',
          'php-version': meta.phpVersion || '',
        });

        window.open(
          `${GITHUB_ISSUES_URL}?${fallbackParams.toString()}`,
          '_blank',
        );
        Craft.cp.displayNotice(
          'Debug log copied to clipboard. Paste it into the debug log field.',
        );
      }
    } catch (err) {
      console.error('Failed to export debug log:', err);
      Craft.cp.displayError('Failed to export debug log.');
    }
  }

  return { handleCommand };
}
