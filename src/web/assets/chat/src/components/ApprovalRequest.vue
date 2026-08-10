<script setup lang="ts">
import type { PendingToolCall } from '../types';

defineProps<{
  toolCalls: PendingToolCall[];
}>();

defineEmits<{
  approve: [];
  reject: [];
}>();

function formatArguments(args: Record<string, unknown>): string {
  return JSON.stringify(args, null, 2);
}
</script>

<template>
  <div class="co-pilot-approval">
    <div class="co-pilot-approval__header">
      <span class="co-pilot-approval__icon">!</span>
      <span>CoPilot wants to make changes</span>
    </div>

    <div
      v-for="(tc, i) in toolCalls"
      :key="tc.id ?? i"
      class="co-pilot-approval__call"
    >
      <div class="co-pilot-approval__call-name">{{ tc.label }}</div>
      <pre class="co-pilot-approval__call-args">{{ formatArguments(tc.arguments) }}</pre>
    </div>

    <div class="co-pilot-approval__actions">
      <button type="button" class="btn submit" @click="$emit('approve')">
        Approve
      </button>
      <button type="button" class="btn" @click="$emit('reject')">
        Reject
      </button>
    </div>
  </div>
</template>

<style scoped>
.co-pilot-approval {
  margin-top: 8px;
  border: 1px solid var(--yellow-300, #fde047);
  background: var(--yellow-050, #fefce8);
  border-radius: 8px;
  padding: 12px;
  font-size: 13px;
}

.co-pilot-approval__header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  margin-bottom: 8px;
}

.co-pilot-approval__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--yellow-300, #fde047);
  color: var(--gray-700, #374151);
  font-weight: 700;
  font-size: 12px;
  flex-shrink: 0;
}

.co-pilot-approval__call {
  margin-bottom: 8px;
}

.co-pilot-approval__call-name {
  font-weight: 600;
  margin-bottom: 4px;
}

.co-pilot-approval__call-args {
  max-height: 240px;
  overflow: auto;
  background: var(--gray-050, #f9fafb);
  border: 1px solid var(--gray-200, #e5e7eb);
  border-radius: 6px;
  padding: 8px;
  font-size: 11px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
  margin: 0;
}

.co-pilot-approval__actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
}
</style>
