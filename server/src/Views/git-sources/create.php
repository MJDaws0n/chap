<?php
/**
 * Create Git Source View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Add Git Source</h1>
            <p class="text-gray-400 mt-1">Connect a Git provider to deploy from repositories</p>
        </div>
        <a href="/git-sources" class="text-gray-400 hover:text-white">← Back</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- GitHub -->
        <div class="bg-gray-800 rounded-lg p-6 hover:bg-gray-750 transition cursor-pointer" onclick="selectProvider('github')">
            <div class="text-center">
                <svg class="w-16 h-16 mx-auto mb-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                </svg>
                <h3 class="text-lg font-semibold">GitHub</h3>
                <p class="text-sm text-gray-400 mt-2">Connect with GitHub App or Personal Access Token</p>
            </div>
        </div>

        <!-- GitLab -->
        <div class="bg-gray-800 rounded-lg p-6 hover:bg-gray-750 transition cursor-pointer" onclick="selectProvider('gitlab')">
            <div class="text-center">
                <svg class="w-16 h-16 mx-auto mb-4 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 0 1-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 0 1 4.82 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.49h8.1l2.44-7.51A.42.42 0 0 1 18.6 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.51L23 13.45a.84.84 0 0 1-.35.94z"/>
                </svg>
                <h3 class="text-lg font-semibold">GitLab</h3>
                <p class="text-sm text-gray-400 mt-2">Connect with GitLab OAuth or Access Token</p>
            </div>
        </div>

        <!-- Bitbucket -->
        <div class="bg-gray-800 rounded-lg p-6 hover:bg-gray-750 transition cursor-pointer" onclick="selectProvider('bitbucket')">
            <div class="text-center">
                <svg class="w-16 h-16 mx-auto mb-4 text-blue-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M.778 1.213a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.9zM14.52 15.53H9.522L8.17 8.466h7.561z"/>
                </svg>
                <h3 class="text-lg font-semibold">Bitbucket</h3>
                <p class="text-sm text-gray-400 mt-2">Connect with Bitbucket App Password</p>
            </div>
        </div>
    </div>

    <form method="POST" action="/git-sources" id="git-source-form" class="bg-gray-800 rounded-lg p-6 space-y-6 hidden">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="type" id="provider-type">

        <h2 class="text-lg font-semibold" id="provider-title">Configure Git Source</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-2">Name</label>
                <input type="text" name="name" id="name" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="My GitHub Account">
            </div>

            <div id="api-url-field" class="hidden">
                <label for="api_url" class="block text-sm font-medium text-gray-300 mb-2">API URL (Self-hosted)</label>
                <input type="url" name="api_url" id="api_url"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="https://gitlab.example.com">
            </div>
        </div>

        <div class="border-t border-gray-700 pt-6">
            <h3 class="text-md font-medium mb-4">Authentication</h3>

            <div id="github-auth" class="space-y-4 hidden">
                <p class="text-sm text-gray-400">You can connect via GitHub App (recommended) or Personal Access Token.</p>
                <div>
                    <label for="github_token" class="block text-sm font-medium text-gray-300 mb-2">Personal Access Token</label>
                    <input type="password" name="access_token" id="github_token"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="ghp_xxxxxxxxxxxxxxxxxxxx">
                    <p class="text-xs text-gray-500 mt-1">Token needs repo scope access</p>
                </div>
            </div>

            <div id="gitlab-auth" class="space-y-4 hidden">
                <div>
                    <label for="gitlab_token" class="block text-sm font-medium text-gray-300 mb-2">Access Token</label>
                    <input type="password" name="access_token" id="gitlab_token"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="glpat-xxxxxxxxxxxxxxxxxxxx">
                    <p class="text-xs text-gray-500 mt-1">Token needs api and read_repository scopes</p>
                </div>
            </div>

            <div id="bitbucket-auth" class="space-y-4 hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="bitbucket_username" class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                        <input type="text" name="username" id="bitbucket_username"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="bitbucket_password" class="block text-sm font-medium text-gray-300 mb-2">App Password</label>
                        <input type="password" name="access_token" id="bitbucket_password"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end space-x-4">
            <button type="button" onclick="resetForm()" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</button>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                Connect
            </button>
        </div>
    </form>
</div>

<script>
function selectProvider(type) {
    document.getElementById('provider-type').value = type;
    document.getElementById('git-source-form').classList.remove('hidden');
    
    // Hide all auth sections
    document.getElementById('github-auth').classList.add('hidden');
    document.getElementById('gitlab-auth').classList.add('hidden');
    document.getElementById('bitbucket-auth').classList.add('hidden');
    document.getElementById('api-url-field').classList.add('hidden');
    
    // Show relevant auth section
    document.getElementById(type + '-auth').classList.remove('hidden');
    
    // Set title
    const titles = {
        github: 'Configure GitHub',
        gitlab: 'Configure GitLab',
        bitbucket: 'Configure Bitbucket'
    };
    document.getElementById('provider-title').textContent = titles[type];
    
    // Show API URL for GitLab (self-hosted option)
    if (type === 'gitlab') {
        document.getElementById('api-url-field').classList.remove('hidden');
    }
}

function resetForm() {
    document.getElementById('git-source-form').classList.add('hidden');
    document.getElementById('git-source-form').reset();
}
</script>
