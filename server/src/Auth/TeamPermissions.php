<?php

namespace Chap\Auth;

/**
 * Central definitions for team-based permissions and built-in roles.
 */
final class TeamPermissions
{
    /**
     * Canonical permission keys used across the product.
     *
     * Keep this list minimal and aligned with actual features/routes.
     */
    public const KEYS = [
        // Team
        'team.settings',
        'team.members',
        'team.roles',

        // Core resources
        'projects',
        'environments',
        'applications',
        'files',
        'deployments',
        'logs',
        'databases',
        'services',
        'templates',
        'git_sources',
        'activity',
    ];

    /**
     * UI metadata: each key maps to the actions that are relevant.
     * This is used to hide irrelevant toggles.
     */
    public const UI = [
        'team.settings' => ['label' => 'Team settings', 'actions' => ['read', 'write']],
        'team.members' => ['label' => 'Member management', 'actions' => ['read', 'execute']],
        'team.roles' => ['label' => 'Role management', 'actions' => ['read', 'write', 'execute']],

        'projects' => ['label' => 'Projects', 'actions' => ['read', 'write']],
        'environments' => ['label' => 'Environments', 'actions' => ['read', 'write']],
        'applications' => ['label' => 'Applications', 'actions' => ['read', 'write', 'execute']],
        'files' => ['label' => 'File viewer/editor', 'actions' => ['read', 'write']],
        'deployments' => ['label' => 'Deployments', 'actions' => ['read', 'execute']],
        'logs' => ['label' => 'Logs', 'actions' => ['read']],
        'databases' => ['label' => 'Databases', 'actions' => ['read', 'write', 'execute']],
        'services' => ['label' => 'Services', 'actions' => ['read', 'write', 'execute']],
        'templates' => ['label' => 'Templates', 'actions' => ['read']],
        'git_sources' => ['label' => 'Git sources', 'actions' => ['read', 'write']],
        'activity' => ['label' => 'Activity', 'actions' => ['read']],
    ];

    /**
     * Built-in roles and hierarchy levels.
     * Owner is implicit via team membership row (team_user.role = 'owner').
     */
    public const BUILTIN_LEVELS = [
        'owner' => 100,
        'admin' => 80,
        'manager' => 60,
        'member' => 40,
        'read_only_member' => 20,
    ];

    /** Reserved role slugs/names (non-creatable for custom roles). */
    public const RESERVED_SLUGS = ['owner', 'admin', 'manager', 'member', 'read_only_member'];

    public const RESERVED_NAMES = ['Owner', 'Admin', 'Manager', 'Member', 'Read-only Member'];

    private function __construct() {}

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_\- ]+/', '', $slug) ?? '';
        $slug = str_replace([' ', '-'], '_', $slug);
        $slug = preg_replace('/_+/', '_', $slug) ?? '';
        return trim($slug, '_');
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array(self::normalizeSlug($slug), self::RESERVED_SLUGS, true);
    }

    public static function isReservedName(string $name): bool
    {
        $n = strtolower(trim($name));
        foreach (self::RESERVED_NAMES as $reserved) {
            if ($n === strtolower($reserved)) {
                return true;
            }
        }
        return false;
    }
}
