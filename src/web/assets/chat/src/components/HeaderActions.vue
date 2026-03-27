<script setup lang="ts">
import type { ProviderInfo } from '../types';

defineProps<{
  conversationId: number | null;
  isExporting: boolean;
  providers: ProviderInfo[];
  currentProvider: string;
  canCreateChat?: boolean;
  canChangeProvider?: boolean;
}>();

defineEmits<{
  'new-chat': [];
  'export-debug': [];
  'update:currentProvider': [value: string];
}>();
</script>

<template>
  <div class="flex" style="gap: 8px; align-items: center">
    <button
      v-if="conversationId"
      type="button"
      class="btn"
      :disabled="isExporting"
      title="Export debug log"
      @click="$emit('export-debug')"
    >
      {{ isExporting ? 'Exporting...' : 'Export Debug' }}
    </button>
    <div v-if="canChangeProvider !== false && providers.length > 1" class="select">
      <select
        :value="currentProvider"
        @change="
          $emit(
            'update:currentProvider',
            ($event.target as HTMLSelectElement).value,
          )
        "
      >
        <option
          v-for="provider in providers"
          :key="provider.handle"
          :value="provider.handle"
        >
          {{ provider.name }}
        </option>
      </select>
    </div>
    <button v-if="canCreateChat !== false" type="button" class="btn submit" @click="$emit('new-chat')">
      New Chat +
    </button>
  </div>
</template>
