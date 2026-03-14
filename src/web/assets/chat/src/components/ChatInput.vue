<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue';
import type { Attachment, SlashCommand } from '../types';
import {
  ALLOWED_FILE_EXTENSIONS,
  isFileAllowed,
  getMaxFileSize,
} from '../utils/attachments';
import { useSlashCommands } from '../composables/useSlashCommands';
import AttachmentPill from './AttachmentPill.vue';

const props = defineProps<{
  isLoading: boolean;
  isStreaming?: boolean;
  attachments: Attachment[];
  compact?: boolean;
  models?: string[];
  currentModel?: string;
  executionMode?: string;
}>();

const emit = defineEmits<{
  send: [text: string, attachments?: Attachment[]];
  cancel: [];
  'add-attachment': [attachment: Attachment];
  'remove-attachment': [index: number];
  'update:currentModel': [value: string];
  'update:executionMode': [value: string];
  command: [name: string];
}>();

const text = ref('');
const showMenu = ref(false);
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const addWrap = ref<HTMLElement | null>(null);
const commandMenu = ref<HTMLElement | null>(null);

const slash = useSlashCommands();

// Param collection state
const pendingCommand = ref<SlashCommand | null>(null);

let paramFileCallback: ((file: File) => void) | null = null;

watch(text, (value) => {
  if (pendingCommand.value) return;
  if (value.startsWith('/')) {
    slash.open(value.slice(1));
  } else if (slash.isOpen.value) {
    slash.close();
  }
});

function selectCommand(cmd: SlashCommand) {
  text.value = '';
  slash.close();

  if (cmd.param) {
    pendingCommand.value = cmd;
    collectParam(cmd);
    return;
  }

  executeCommand(cmd);
  nextTick(() => {
    if (textarea.value) autoGrow({ target: textarea.value } as unknown as Event);
  });
}

function collectParam(cmd: SlashCommand) {
  const param = cmd.param!;

  if (param.type === 'entry') {
    Craft.createElementSelectorModal('craft\\elements\\Entry', {
      multiSelect: false,
      onSelect: (elements) => {
        if (elements.length) {
          if (!elements[0].siteId) {
            console.warn('[CoPilot] Element selector did not return siteId for entry', elements[0].id);
          }
          finishWithAttachment(cmd, { type: 'entry', id: elements[0].id, siteId: elements[0].siteId, label: elements[0].label });
        } else {
          cancelParam();
        }
      },
    });
  } else if (param.type === 'asset') {
    Craft.createElementSelectorModal('craft\\elements\\Asset', {
      multiSelect: false,
      onSelect: (elements) => {
        if (elements.length) {
          finishWithAttachment(cmd, { type: 'asset', id: elements[0].id, label: elements[0].label });
        } else {
          cancelParam();
        }
      },
    });
  } else if (param.type === 'file') {
    if (fileInput.value) {
      paramFileCallback = (file: File) => {
        finishWithAttachment(cmd, { type: 'file', file, label: file.name });
      };
      fileInput.value.value = '';
      fileInput.value.click();
    }
  }
  // 'text' is handled inline via the template
}

function finishWithAttachment(cmd: SlashCommand, att: Attachment) {
  pendingCommand.value = null;
  executeCommand(cmd, [att]);
}

function submitTextParam() {
  const cmd = pendingCommand.value;
  if (!cmd) return;

  const val = text.value.trim();
  if (!val) return;

  pendingCommand.value = null;
  text.value = '';
  executeCommand(cmd, undefined, val);
}

function cancelParam() {
  pendingCommand.value = null;
  text.value = '';
}

function executeCommand(cmd: SlashCommand, atts?: Attachment[], textParam?: string) {
  if (cmd.prompt) {
    let prompt = cmd.prompt;
    if (textParam) {
      prompt = prompt + '\n' + textParam;
    }
    emit('send', prompt, atts);
  } else {
    emit('command', cmd.name);
  }
}

function handleKeydown(e: KeyboardEvent) {
  // While collecting a text param, Enter submits the value
  if (pendingCommand.value && pendingCommand.value.param?.type === 'text') {
    if (e.key === 'Escape') {
      e.preventDefault();
      cancelParam();
      return;
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      submitTextParam();
      return;
    }
    return;
  }

  if (slash.isOpen.value && slash.filteredCommands.value.length > 0) {
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      slash.moveUp();
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      slash.moveDown();
      return;
    }
    if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault();
      const cmd = slash.getSelected();
      if (cmd) selectCommand(cmd);
      return;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      slash.close();
      text.value = '';
      return;
    }
  }

  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    submit();
  }
}

function submit() {
  if (props.isLoading) return;
  const msg = text.value.trim();
  if (!msg) return;

  // If it's an exact command match, execute it
  if (msg.startsWith('/')) {
    const cmd = slash.filteredCommands.value.find(
      (c) => `/${c.name}` === msg,
    );
    if (cmd) {
      selectCommand(cmd);
      return;
    }
  }

  emit('send', msg);
  text.value = '';
  slash.close();
  nextTick(() => {
    if (textarea.value) autoGrow({ target: textarea.value } as unknown as Event);
  });
}

function autoGrow(e: Event) {
  const el = e.target as HTMLTextAreaElement;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 200) + 'px';
}

function toggleMenu() {
  showMenu.value = !showMenu.value;
}

function selectEntry() {
  showMenu.value = false;
  Craft.createElementSelectorModal('craft\\elements\\Entry', {
    multiSelect: false,
    onSelect: (elements) => {
      if (elements.length) {
        if (!elements[0].siteId) {
          console.warn('[CoPilot] Element selector did not return siteId for entry', elements[0].id);
        }
        emit('add-attachment', {
          type: 'entry',
          id: elements[0].id,
          siteId: elements[0].siteId,
          label: elements[0].label,
        });
      }
    },
  });
}

function selectAsset() {
  showMenu.value = false;
  Craft.createElementSelectorModal('craft\\elements\\Asset', {
    multiSelect: false,
    onSelect: (elements) => {
      if (elements.length) {
        emit('add-attachment', {
          type: 'asset',
          id: elements[0].id,
          label: elements[0].label,
        });
      }
    },
  });
}

function uploadFile() {
  showMenu.value = false;
  if (fileInput.value) {
    fileInput.value.value = '';
    fileInput.value.click();
  }
}

function handleFileSelect(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;

  if (!isFileAllowed(file.name)) {
    alert(`Unsupported file type. Allowed: ${ALLOWED_FILE_EXTENSIONS}`);
    return;
  }

  if (file.size > getMaxFileSize()) {
    alert('File is too large. Maximum size is 100 KB.');
    return;
  }

  if (paramFileCallback) {
    paramFileCallback(file);
    paramFileCallback = null;
    return;
  }

  emit('add-attachment', {
    type: 'file',
    file,
    label: file.name,
  });
}

function handleClickOutside(e: MouseEvent) {
  if (showMenu.value && addWrap.value && !addWrap.value.contains(e.target as Node)) {
    showMenu.value = false;
  }
}

function focus() {
  textarea.value?.focus();
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});

defineExpose({ focus });
</script>

<template>
  <div class="co-pilot-input">
    <div
      v-if="slash.isOpen.value && slash.filteredCommands.value.length > 0"
      ref="commandMenu"
      class="co-pilot-input__command-menu"
    >
      <button
        v-for="(cmd, i) in slash.filteredCommands.value"
        :key="cmd.name"
        type="button"
        class="co-pilot-input__command-item"
        :class="{ 'is-selected': i === slash.selectedIndex.value }"
        @click="selectCommand(cmd)"
        @mouseenter="slash.selectedIndex.value = i"
      >
        <span class="co-pilot-input__command-name">/{{ cmd.name }}</span>
        <span class="co-pilot-input__command-desc">{{ cmd.description }}</span>
      </button>
    </div>
    <div
      v-if="pendingCommand?.param?.type === 'text'"
      class="co-pilot-input__param-bar"
    >
      <span class="co-pilot-input__param-label">
        /{{ pendingCommand.name }} — {{ pendingCommand.param.label }}
      </span>
      <button
        type="button"
        class="co-pilot-input__param-cancel btn small"
        @click="cancelParam"
      >
        Cancel
      </button>
    </div>
    <div v-if="attachments.length > 0" class="co-pilot-input__attachments">
      <AttachmentPill
        v-for="(att, i) in attachments"
        :key="i"
        :attachment="att"
        @remove="$emit('remove-attachment', i)"
      />
    </div>
    <textarea
      ref="textarea"
      class="co-pilot-input__textarea"
      v-model="text"
      :placeholder="pendingCommand?.param?.type === 'text' ? pendingCommand.param.label + '...' : (compact ? 'Ask about this entry...' : 'Ask CoPilot...')"
      rows="1"
      @keydown="handleKeydown"
      @input="autoGrow"
    />
    <div class="co-pilot-input__toolbar">
      <div class="co-pilot-input__toolbar-left">
        <div v-if="!compact" ref="addWrap" class="co-pilot-input__add-wrap">
          <button
            type="button"
            class="co-pilot-input__add-btn btn"
            title="Add attachment"
            @click="toggleMenu"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="20"
              height="20"
              viewBox="0 0 24 24"
            >
              <path
                fill="none"
                stroke="currentColor"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 5v14m-7-7h14"
              />
            </svg>
          </button>
          <div v-if="showMenu" class="co-pilot-input__menu">
            <button
              type="button"
              class="co-pilot-input__menu-item"
              @click="selectEntry"
            >
              Select Entry
            </button>
            <button
              type="button"
              class="co-pilot-input__menu-item"
              @click="selectAsset"
            >
              Select Asset
            </button>
            <button
              type="button"
              class="co-pilot-input__menu-item"
              @click="uploadFile"
            >
              Upload File
            </button>
          </div>
        </div>
        <div v-if="!compact" class="co-pilot-input__mode-select select">
          <select
            :value="executionMode || 'supervised'"
            @change="
              $emit(
                'update:executionMode',
                ($event.target as HTMLSelectElement).value,
              )
            "
          >
            <option value="supervised">Supervised</option>
            <option value="autonomous">Autonomous</option>
          </select>
        </div>
      </div>
      <div class="co-pilot-input__toolbar-right">
        <div
          v-if="!compact && props.models && props.models.length > 0"
          class="co-pilot-input__model-select select"
        >
          <select
            :value="currentModel"
            @change="
              $emit(
                'update:currentModel',
                ($event.target as HTMLSelectElement).value,
              )
            "
          >
            <option
              v-for="model in props.models"
              :key="model"
              :value="model"
            >
              {{ model }}
            </option>
          </select>
        </div>
        <button
          v-if="isStreaming"
          type="button"
          class="co-pilot-input__cancel-btn btn"
          title="Cancel"
          @click="$emit('cancel')"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="20"
            height="20"
            viewBox="0 0 24 24"
          >
            <rect
              x="6"
              y="6"
              width="12"
              height="12"
              rx="2"
              fill="currentColor"
            />
          </svg>
        </button>
        <button
          v-else
          type="button"
          class="co-pilot-input__send-btn btn submit"
          :class="isLoading || !text.trim() ? 'disabled' : ''"
          title="Send message"
          @click="submit"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
          >
            <path
              fill="none"
              stroke="currentColor"
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11zm7.318-19.539l-10.94 10.939"
            />
          </svg>
        </button>
      </div>
    </div>
    <input
      ref="fileInput"
      type="file"
      :accept="ALLOWED_FILE_EXTENSIONS"
      style="display: none"
      @change="handleFileSelect"
    />
  </div>
</template>
