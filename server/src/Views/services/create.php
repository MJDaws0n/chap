<?php
/**
 * Create Service View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Deploy Service</h1>
            <p class="text-gray-400 mt-1">Deploy a one-click service from a template</p>
        </div>
        <a href="/services" class="text-gray-400 hover:text-white">← Back</a>
    </div>

    <form method="POST" action="/services" class="bg-gray-800 rounded-lg p-6 space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Service Name</label>
                <input type="text" name="name" id="name" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="my-service">
            </div>

            <div>
                <label for="template_id" class="block text-sm font-medium text-gray-300 mb-2">Template</label>
                <select name="template_id" id="template_id" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="">Select a template...</option>
                    <?php foreach ($templates as $template): ?>
                        <option value="<?= $template->id ?>"><?= e($template->name) ?> - <?= e($template->description ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="node_id" class="block text-sm font-medium text-gray-300 mb-2">Node</label>
                <select name="node_id" id="node_id" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <?php foreach ($nodes as $node): ?>
                        <option value="<?= $node->id ?>"><?= e($node->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="environment_id" class="block text-sm font-medium text-gray-300 mb-2">Environment</label>
                <select name="environment_id" id="environment_id" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <?php foreach ($environments as $env): ?>
                        <option value="<?= $env->id ?>"><?= e($env->name) ?> (<?= e($env->project()->name ?? 'No Project') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="template-config" class="hidden border-t border-gray-700 pt-6">
            <h3 class="text-lg font-medium mb-4">Service Configuration</h3>
            <div id="config-fields" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Dynamic configuration fields will be inserted here -->
            </div>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="/services" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                Deploy Service
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('template_id').addEventListener('change', function() {
    const templateConfig = document.getElementById('template-config');
    const configFields = document.getElementById('config-fields');
    
    if (this.value) {
        // In a real implementation, fetch template configuration and render fields
        templateConfig.classList.remove('hidden');
    } else {
        templateConfig.classList.add('hidden');
    }
});
</script>
