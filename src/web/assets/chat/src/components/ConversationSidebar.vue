<script setup lang="ts">
import type { ConversationSummary } from '../types';

const props = defineProps<{
  conversations: ConversationSummary[];
  activeId: number | null;
  currentUserId?: number | null;
  canDeleteOwn?: boolean;
  canDeleteOthers?: boolean;
}>();

defineEmits<{
  select: [id: number];
  delete: [id: number];
}>();

function canDelete(conv: ConversationSummary): boolean {
  const isOwn = conv.userId === props.currentUserId;
  if (isOwn) return props.canDeleteOwn !== false;
  return props.canDeleteOthers === true;
}
</script>

<template>
  <nav aria-label="Conversations">
    <ul>
      <li v-if="conversations.length === 0">
        <span class="co-pilot-sidebar-empty">No conversations yet</span>
      </li>
      <li
        v-for="conv in conversations"
        :key="conv.id"
        class="co-pilot-sidebar-item"
      >
        <a
          :class="{ sel: conv.id === activeId }"
          href="#"
          @click.prevent="$emit('select', conv.id)"
        >
          <span class="label">{{ conv.title }}</span>
          <span
            v-if="canDelete(conv)"
            class="co-pilot-sidebar-delete"
            role="button"
            title="Delete conversation"
            @click.stop.prevent="$emit('delete', conv.id)"
            >&times;</span
          >
        </a>
      </li>
    </ul>
  </nav>
</template>
