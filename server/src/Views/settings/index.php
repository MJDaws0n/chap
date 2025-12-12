<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-bold">Settings</h1>
        <p class="text-gray-400">Configure your application preferences</p>
    </div>

    <!-- General Settings -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">General Settings</h2>
            <p class="text-sm text-gray-400">Configure general application settings.</p>
        </div>
        <form action="/settings" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            
            <div>
                <label for="default_node" class="block text-sm font-medium text-gray-300 mb-2">Default Node</label>
                <select name="default_node" id="default_node" 
                        class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Select a default node...</option>
                    <!-- Nodes would be populated here -->
                </select>
                <p class="text-xs text-gray-500 mt-1">New applications will be deployed to this node by default.</p>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                    Save Settings
                </button>
            </div>
        </form>
    </div>

    <!-- API Tokens -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">API Tokens</h2>
            <p class="text-sm text-gray-400">Manage your API access tokens.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-400 mb-4">
                API tokens allow you to authenticate with the Chap API. Keep your tokens secure and never share them.
            </p>
            <div class="bg-gray-900 rounded-lg p-4 mb-4">
                <p class="text-xs text-gray-500 mb-2">Your API endpoint:</p>
                <code class="text-green-400 text-sm"><?= e(url('/api/v1')) ?></code>
            </div>
            <button type="button" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors" 
                    onclick="chapSwal({title: 'Not implemented', text: 'Token generation not implemented yet', icon: 'info', confirmButtonText: 'OK'}); return false;">
                Generate New Token
            </button>
        </div>
    </div>

    <!-- Notifications -->
    <div class="bg-gray-800 rounded-lg border border-gray-700">
        <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">Notifications</h2>
            <p class="text-sm text-gray-400">Configure how you receive notifications.</p>
        </div>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium">Deployment Notifications</p>
                    <p class="text-sm text-gray-400">Get notified when deployments complete or fail</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" class="sr-only peer" checked>
                    <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
            
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium">Node Status Alerts</p>
                    <p class="text-sm text-gray-400">Get notified when nodes go offline</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" class="sr-only peer" checked>
                    <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
        </div>
    </div>
</div>
