<?php
/**
 * Edit Service View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Edit Service</h1>
            <p class="text-gray-400 mt-1"><?= e($service->name) ?></p>
        </div>
        <a href="/services/<?= $service->uuid ?>" class="text-gray-400 hover:text-white">← Back</a>
    </div>

    <form method="POST" action="/services/<?= $service->uuid ?>" class="bg-gray-800 rounded-lg p-6 space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="_method" value="PUT">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Service Name</label>
                <input type="text" name="name" id="name" required
                    value="<?= e($service->name) ?>"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            </div>

            <div>
                <label for="fqdn" class="block text-sm font-medium text-gray-300 mb-2">Domain(s)</label>
                <input type="text" name="fqdn" id="fqdn"
                    value="<?= e($service->fqdn ?? '') ?>"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="https://app.example.com">
                <p class="text-xs text-gray-500 mt-1">Separate multiple domains with commas</p>
            </div>
        </div>

        <div class="border-t border-gray-700 pt-6">
            <h3 class="text-lg font-medium mb-4">Environment Variables</h3>
            <div id="env-vars" class="space-y-3">
                <?php 
                $envVars = json_decode($service->configuration ?? '{}', true) ?? [];
                foreach ($envVars as $key => $value): 
                ?>
                    <div class="flex space-x-3 env-row">
                        <input type="text" name="env_keys[]" value="<?= e($key) ?>"
                            class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="KEY">
                        <input type="text" name="env_values[]" value="<?= e($value) ?>"
                            class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="VALUE">
                        <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-300 px-2">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addEnvVar()" class="mt-3 text-blue-400 hover:text-blue-300 text-sm">
                + Add Environment Variable
            </button>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="/services/<?= $service->uuid ?>" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                Update Service
            </button>
        </div>
    </form>
</div>

<script>
function addEnvVar() {
    const container = document.getElementById('env-vars');
    const row = document.createElement('div');
    row.className = 'flex space-x-3 env-row';
    row.innerHTML = `
        <input type="text" name="env_keys[]"
            class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            placeholder="KEY">
        <input type="text" name="env_values[]"
            class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            placeholder="VALUE">
        <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-300 px-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </button>
    `;
    container.appendChild(row);
}
</script>
