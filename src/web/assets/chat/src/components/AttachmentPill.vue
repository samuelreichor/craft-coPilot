<script setup lang="ts">
import { computed } from 'vue';
import type { Attachment } from '../types';

const props = defineProps<{
  attachment: Attachment;
  readonly?: boolean;
}>();

defineEmits<{
  remove: [];
}>();

const icon = computed(() => {
  switch (props.attachment.type) {
    case 'entry':
      return '\u{1F4C4}';
    case 'asset':
      return '\u{1F5BC}';
    case 'file':
      return '\u{1F4CE}';
    default:
      return '';
  }
});

const elementUrl = computed(() => {
  if (!props.readonly || !props.attachment.id) return null;
  if (props.attachment.type !== 'entry' && props.attachment.type !== 'asset') return null;
  const params: Record<string, string> = {
    elementId: String(props.attachment.id),
  };
  if (props.attachment.siteId) {
    params.siteId = String(props.attachment.siteId);
  }
  return Craft.getActionUrl('co-pilot/chat/open-element', params);
});
</script>

<template>
  <a
    v-if="elementUrl"
    :href="elementUrl"
    target="_blank"
    class="co-pilot-attachment-pill co-pilot-attachment-pill--link"
  >
    <span v-if="icon" class="co-pilot-attachment-pill__icon">{{ icon }}</span>
    <span class="co-pilot-attachment-pill__label">{{ attachment.label }}</span>
    <span class="co-pilot-attachment-pill__arrow">&#8599;</span>
  </a>
  <span
    v-else
    class="co-pilot-attachment-pill"
    :class="{ 'co-pilot-attachment-pill--readonly': readonly }"
  >
    <span v-if="icon" class="co-pilot-attachment-pill__icon">{{ icon }}</span>
    <span class="co-pilot-attachment-pill__label">{{ attachment.label }}</span>
    <button
      v-if="!readonly"
      type="button"
      class="co-pilot-attachment-pill__remove"
      title="Remove"
      @click="$emit('remove')"
    >
      &times;
    </button>
  </span>
</template>
