import { ref, onMounted } from 'vue';
import { apiPost } from './useCraftApi';
import type { ModelsResponse, ProviderInfo } from '../types';

export function useModels() {
  const models = ref<string[]>([]);
  const currentModel = ref('');
  const providerName = ref('');
  const currentProvider = ref('');
  const providers = ref<ProviderInfo[]>([]);

  onMounted(async () => {
    try {
      const data = await apiPost<ModelsResponse>('co-pilot/chat/get-models');
      models.value = data.models || [];
      currentModel.value =
        data.currentModel || (data.models && data.models[0]) || '';
      providerName.value = data.providerName || '';
      currentProvider.value = data.provider || '';
      providers.value = data.providers || [];
    } catch (err) {
      console.error('Failed to load models:', err);
    }
  });

  function switchProvider(handle: string) {
    const provider = providers.value.find((p) => p.handle === handle);
    if (!provider) return;

    currentProvider.value = handle;
    models.value = provider.models;
    currentModel.value =
      provider.defaultModel || (provider.models[0] ?? '');
    providerName.value = provider.name;
  }

  return {
    models,
    currentModel,
    providerName,
    currentProvider,
    providers,
    switchProvider,
  };
}
