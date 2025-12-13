<div class="mb-8">
    <a href="/nodes" class="text-gray-400 hover:text-white text-sm flex items-center mb-4">
        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
        Back to Nodes
    </a>
    <h1 class="text-3xl font-bold">Add Node</h1>
    <p class="text-gray-400">Connect a server to deploy your applications</p>
</div>

<div class="max-w-2xl">
    <form action="/nodes" method="POST" class="bg-gray-800 rounded-lg border border-gray-700 p-6 space-y-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <div>
            <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Node Name</label>
            <input 
                type="text" 
                id="name" 
                name="name" 
                required
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="production-server-1"
                value="<?= htmlspecialchars($old['name'] ?? '') ?>"
            >
                <script>
                const nameInput = document.getElementById('name');
                nameInput.addEventListener('input', function(e) {
                    const caret = nameInput.selectionStart;
                    let value = nameInput.value;
                    // Replace spaces with dash as you type
                    value = value.replace(/ /g, '-');
                    // Only allow a-z, 0-9, and dash, force lowercase
                    value = value.replace(/[^a-z0-9-]/gi, '').toLowerCase();
                    if (nameInput.value !== value) {
                        nameInput.value = value;
                        nameInput.setSelectionRange(caret, caret);
                    }
                });
                nameInput.addEventListener('paste', function(e) {
                    e.preventDefault();
                    let paste = (e.clipboardData || window.clipboardData).getData('text');
                    paste = paste.replace(/ /g, '-').replace(/[^a-z0-9-]/gi, '').toLowerCase();
                    nameInput.value = paste;
                });
                </script>
                <script>
                const nodeForm = nameInput.closest('form');
                nodeForm.addEventListener('submit', function(e) {
                    // Remove trailing dashes before submit
                    nameInput.value = nameInput.value.replace(/-+$/, '');
                });
                </script>
            <p class="mt-1 text-xs text-gray-500">A unique identifier for this server (lowercase, no spaces)</p>
            <?php if (!empty($errors['name'])): ?>
                <p class="mt-1 text-sm text-red-400"><?= htmlspecialchars($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-gray-300 mb-2">Description <span class="text-gray-500">(optional)</span></label>
            <input 
                type="text" 
                id="description" 
                name="description" 
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Production server in AWS us-east-1"
                value="<?= htmlspecialchars($old['description'] ?? '') ?>"
            >
        </div>

        <div>
            <label for="logs_websocket_url" class="block text-sm font-medium text-gray-300 mb-2">Live Logs WebSocket URL <span class="text-gray-500">(optional)</span></label>
            <input 
                type="text" 
                id="logs_websocket_url" 
                name="logs_websocket_url" 
                class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="wss://node.example.com:6002 or ws://192.168.1.10:6002"
                value="<?= htmlspecialchars($old['logs_websocket_url'] ?? '') ?>"
            >
            <p class="mt-1 text-xs text-gray-500">Direct WebSocket URL for live logs (browsers connect here). Leave blank to use polling.</p>
        </div>

        <div class="bg-blue-900/20 border border-blue-700 rounded-lg p-4">
            <div class="flex items-start space-x-3">
                <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <h4 class="text-sm font-medium text-blue-400">How it works</h4>
                    <p class="mt-1 text-sm text-gray-400">
                        After creating the node, you'll get a token. Install the Chap agent on your server 
                        and it will connect back to this dashboard via WebSocket. No SSH or firewall changes needed.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-gray-700/50 rounded-lg p-4">
            <h4 class="text-sm font-medium text-gray-300 mb-2">Requirements on target server</h4>
            <ul class="text-sm text-gray-400 list-disc list-inside space-y-1">
                <li>Docker installed and running</li>
                <li>Outbound connectivity to this Chap server (port <?= config('WS_PORT', 8081) ?>)</li>
            </ul>
        </div>

        <div class="flex items-center justify-end space-x-4 pt-4 border-t border-gray-700">
            <a href="/nodes" class="px-4 py-2 text-gray-400 hover:text-white transition-colors">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                Create Node
            </button>
        </div>
    </form>
</div>
