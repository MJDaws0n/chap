<?php
/**
 * Templates Index View
 */

$templatesByCategory = $templatesByCategory ?? [];
$allTemplates = $templates ?? [];

$categories = array_keys($templatesByCategory);
sort($categories);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Application Templates</h1>
                <p class="page-header-description">Deploy popular apps and services with a template</p>
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-4">
        <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
            <div class="mb-1 overflow-x-auto">
                <div class="tabs flex-wrap" id="templateTabs">
                    <button type="button" class="tab active" data-category="__all">All</button>
                    <?php foreach ($categories as $cat): ?>
                        <button type="button" class="tab" data-category="<?= e($cat) ?>"><?= e($cat) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="w-full md:w-80">
                <input id="templateSearch" class="input w-full" type="text" placeholder="Search templates..." />
            </div>
        </div>

        <?php if (empty($allTemplates)): ?>
            <div class="card">
                <div class="card-body">
                    <p class="text-secondary">No templates found.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="templatesGrid">
                <?php foreach ($allTemplates as $t): ?>
                    <?php
                        $cat = trim((string)($t->category ?? ''));
                        if ($cat === '') $cat = 'Other';
                        $desc = trim((string)($t->description ?? ''));
                        $isOfficial = !empty($t->is_official);
                    ?>
                    <div class="card card-glass card-hover h-full flex flex-col template-card" data-category="<?= e($cat) ?>" data-name="<?= e(strtolower((string)$t->name)) ?>" data-desc="<?= e(strtolower($desc)) ?>">
                        <div class="card-body flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-4 min-w-0 flex-1">
                                    <div class="icon-box icon-box-blue icon-box-sm flex-shrink-0">
                                        <?php if (!empty($t->icon)): ?>
                                            <span class="font-medium" aria-hidden="true"><?= e($t->icon) ?></span>
                                        <?php else: ?>
                                            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="font-medium truncate"><?= e($t->name) ?></p>
                                            <?php if ($isOfficial): ?>
                                                <span class="badge badge-success">Official</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($desc !== ''): ?>
                                            <p class="text-sm text-secondary line-clamp-2"><?= e($desc) ?></p>
                                        <?php else: ?>
                                            <p class="text-sm text-tertiary">No description</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge badge-neutral"><?= e($cat) ?></span>
                            </div>
                        </div>
                        <div class="card-footer" style="background-color: transparent;">
                            <a href="/templates/<?= e($t->slug) ?>" class="btn btn-secondary w-full">View & Deploy</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const tabs = document.querySelectorAll('#templateTabs .tab');
    const cards = document.querySelectorAll('.template-card');
    const search = document.getElementById('templateSearch');

    function applyFilters() {
        const active = document.querySelector('#templateTabs .tab.active');
        const category = active ? active.getAttribute('data-category') : '__all';
        const q = (search && search.value ? search.value : '').toLowerCase().trim();

        cards.forEach(card => {
            const cat = card.getAttribute('data-category') || '';
            const name = card.getAttribute('data-name') || '';
            const desc = card.getAttribute('data-desc') || '';

            const catOk = (category === '__all') || (cat === category);
            const qOk = !q || name.includes(q) || desc.includes(q);
            card.style.display = (catOk && qOk) ? '' : 'none';
        });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            applyFilters();
        });
    });

    if (search) {
        search.addEventListener('input', applyFilters);
    }
})();
</script>
