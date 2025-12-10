<?php
/**
 * Create Application View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center space-x-2 text-sm text-gray-400 mb-2">
                <a href="/projects" class="hover:text-white">Projects</a>
                <span>/</span>
                <a href="/projects/<?= $project->uuid ?>" class="hover:text-white"><?= e($project->name) ?></a>
                <span>/</span>
                <a href="/environments/<?= $environment->uuid ?>" class="hover:text-white"><?= e($environment->name) ?></a>
                <span>/</span>
                <span>New Application</span>
            </div>
            <h1 class="text-2xl font-bold">New Application</h1>
            <p class="text-gray-400 mt-1">Deploy a new application to <?= e($environment->name) ?></p>
        </div>
    </div>

    <form method="POST" action="/environments/<?= $environment->uuid ?>/applications" class="space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <!-- Basic Info -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Basic Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Application Name *</label>
                    <input type="text" name="name" id="name" required
                        value="<?= e(old('name')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="my-awesome-app">
                    <?php if (!empty($_SESSION['_errors']['name'])): ?>
                        <p class="text-red-400 text-sm mt-1"><?= e($_SESSION['_errors']['name']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="node_uuid" class="block text-sm font-medium text-gray-300 mb-2">Deploy to Node</label>
                    <select name="node_uuid" id="node_uuid"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="">Select a node (optional)</option>
                        <?php foreach ($nodes as $node): ?>
                            <option value="<?= $node->uuid ?>"><?= e($node->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">You can assign a node later</p>
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-300 mb-2">Description</label>
                    <textarea name="description" id="description" rows="2"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="Optional description"><?= e(old('description')) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Source -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Source Code</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="git_repository" class="block text-sm font-medium text-gray-300 mb-2">Git Repository</label>
                    <input type="text" name="git_repository" id="git_repository"
                        value="<?= e(old('git_repository')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="https://github.com/user/repo.git">
                </div>

                <div>
                    <label for="git_branch" class="block text-sm font-medium text-gray-300 mb-2">Branch</label>
                    <input type="text" name="git_branch" id="git_branch"
                        value="<?= e(old('git_branch', 'main')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="main">
                </div>
            </div>
        </div>

        <!-- Build Configuration -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Build Configuration</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="build_pack" class="block text-sm font-medium text-gray-300 mb-2">Build Pack</label>
                    <select name="build_pack" id="build_pack"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="dockerfile" <?= old('build_pack') === 'dockerfile' ? 'selected' : '' ?>>Dockerfile</option>
                        <option value="nixpacks" <?= old('build_pack') === 'nixpacks' ? 'selected' : '' ?>>Nixpacks (Auto-detect)</option>
                        <option value="static" <?= old('build_pack') === 'static' ? 'selected' : '' ?>>Static Site</option>
                        <option value="docker-compose" <?= old('build_pack') === 'docker-compose' ? 'selected' : '' ?>>Docker Compose</option>
                    </select>
                </div>

                <div>
                    <label for="dockerfile_path" class="block text-sm font-medium text-gray-300 mb-2">Dockerfile Path</label>
                    <input type="text" name="dockerfile_path" id="dockerfile_path"
                        value="<?= e(old('dockerfile_path', 'Dockerfile')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div>
                    <label for="build_context" class="block text-sm font-medium text-gray-300 mb-2">Build Context</label>
                    <input type="text" name="build_context" id="build_context"
                        value="<?= e(old('build_context', '.')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>

                <div>
                    <label for="port" class="block text-sm font-medium text-gray-300 mb-2">Application Port</label>
                    <input type="number" name="port" id="port"
                        value="<?= e(old('port', '3000')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="3000">
                </div>
            </div>
        </div>

        <!-- Domains -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Domains</h2>
            <div>
                <label for="domains" class="block text-sm font-medium text-gray-300 mb-2">Custom Domains</label>
                <input type="text" name="domains" id="domains"
                    value="<?= e(old('domains')) ?>"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="app.example.com, www.example.com">
                <p class="text-xs text-gray-500 mt-1">Separate multiple domains with commas. Make sure DNS is configured.</p>
            </div>
        </div>

        <!-- Environment Variables -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Environment Variables</h2>
            <div>
                <label for="environment_variables" class="block text-sm font-medium text-gray-300 mb-2">Variables (KEY=value format)</label>
                <textarea name="environment_variables" id="environment_variables" rows="6"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white font-mono text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="NODE_ENV=production
DATABASE_URL=mysql://...
SECRET_KEY=your-secret"><?= e(old('environment_variables')) ?></textarea>
                <p class="text-xs text-gray-500 mt-1">One variable per line in KEY=value format</p>
            </div>
        </div>

        <!-- Resources -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Resource Limits</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="memory_limit" class="block text-sm font-medium text-gray-300 mb-2">Memory Limit</label>
                    <select name="memory_limit" id="memory_limit"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="256m">256 MB</option>
                        <option value="512m" selected>512 MB</option>
                        <option value="1g">1 GB</option>
                        <option value="2g">2 GB</option>
                        <option value="4g">4 GB</option>
                        <option value="8g">8 GB</option>
                    </select>
                </div>

                <div>
                    <label for="cpu_limit" class="block text-sm font-medium text-gray-300 mb-2">CPU Limit</label>
                    <select name="cpu_limit" id="cpu_limit"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="0.5">0.5 CPU</option>
                        <option value="1" selected>1 CPU</option>
                        <option value="2">2 CPUs</option>
                        <option value="4">4 CPUs</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Health Check -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Health Check</h2>
            <div class="space-y-4">
                <div class="flex items-center">
                    <input type="checkbox" name="health_check_enabled" id="health_check_enabled"
                        class="w-4 h-4 bg-gray-700 border-gray-600 rounded focus:ring-blue-500"
                        <?= old('health_check_enabled') ? 'checked' : '' ?>>
                    <label for="health_check_enabled" class="ml-2 text-sm text-gray-300">Enable health checks</label>
                </div>

                <div>
                    <label for="health_check_path" class="block text-sm font-medium text-gray-300 mb-2">Health Check Path</label>
                    <input type="text" name="health_check_path" id="health_check_path"
                        value="<?= e(old('health_check_path', '/health')) ?>"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="/health">
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end space-x-4">
            <a href="/environments/<?= $environment->uuid ?>" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                Create Application
            </button>
        </div>
    </form>
</div>

<?php unset($_SESSION['_errors'], $_SESSION['_old_input']); ?>
