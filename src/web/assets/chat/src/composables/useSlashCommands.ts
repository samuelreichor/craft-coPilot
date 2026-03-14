import { ref, computed } from 'vue';
import type { SlashCommand } from '../types';
import { apiPost } from './useCraftApi';

const builtinCommands: SlashCommand[] = [
  {
    name: 'new-chat',
    description: 'Start a new conversation',
  },
  {
    name: 'report-bug',
    description: 'Report a bug on GitHub',
  },
  {
    name: 'compact',
    description: 'Summarize conversation to save tokens',
  },
];

const backendCommands = ref<SlashCommand[]>([]);
const loaded = ref(false);

async function fetchCommands() {
  if (loaded.value) return;

  try {
    const data = await apiPost<SlashCommand[]>('co-pilot/chat/get-commands');
    backendCommands.value = data;
    loaded.value = true;
  } catch (err) {
    console.error('Failed to load commands:', err);
  }
}

export function useSlashCommands() {
  const query = ref('');
  const isOpen = ref(false);
  const selectedIndex = ref(0);

  if (!loaded.value) {
    fetchCommands();
  }

  const allCommands = computed(() => [
    ...builtinCommands,
    ...backendCommands.value,
  ]);

  const filteredCommands = computed(() => {
    if (!query.value) return allCommands.value;

    const q = query.value.toLowerCase();
    return allCommands.value.filter(
      (cmd) =>
        cmd.name.toLowerCase().includes(q) ||
        cmd.description.toLowerCase().includes(q),
    );
  });

  function open(filter: string) {
    query.value = filter;
    selectedIndex.value = 0;
    isOpen.value = true;
  }

  function close() {
    isOpen.value = false;
    query.value = '';
    selectedIndex.value = 0;
  }

  function moveUp() {
    if (selectedIndex.value > 0) {
      selectedIndex.value--;
    }
  }

  function moveDown() {
    if (selectedIndex.value < filteredCommands.value.length - 1) {
      selectedIndex.value++;
    }
  }

  function getSelected(): SlashCommand | null {
    return filteredCommands.value[selectedIndex.value] ?? null;
  }

  return {
    isOpen,
    query,
    selectedIndex,
    filteredCommands,
    open,
    close,
    moveUp,
    moveDown,
    getSelected,
  };
}
