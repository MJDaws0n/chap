#!/usr/bin/env php
<?php
/**
 * Chap Test Runner
 * 
 * Runs all tests and outputs results.
 * Usage: php bin/test.php [--filter=TestName]
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Colors for output
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('BLUE', "\033[34m");
define('RESET', "\033[0m");

// Set up test environment
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';
$_ENV['APP_SECRET'] = 'test_secret_key_for_testing_only_32chars';
$_ENV['DB_HOST'] = getenv('DB_HOST') ?: 'mysql';
$_ENV['DB_PORT'] = getenv('DB_PORT') ?: '3306';
$_ENV['DB_DATABASE'] = getenv('DB_DATABASE') ?: 'chap';
$_ENV['DB_USERNAME'] = getenv('DB_USERNAME') ?: 'chap';
$_ENV['DB_PASSWORD'] = getenv('DB_PASSWORD') ?: 'chap_secret';

foreach ($_ENV as $key => $value) {
    putenv("$key=$value");
}

use Chap\App;
use Chap\Config;
use Chap\Database\Connection;

Config::load();

// Boot the application (needed for models)
$app = new App();
$app->boot();

echo BLUE . "
╔═══════════════════════════════════════╗
║         CHAP TEST SUITE               ║
╚═══════════════════════════════════════╝
" . RESET . "\n";

// Test results tracking
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$errors = [];

/**
 * Assert helper
 */
function assert_true($condition, string $message): bool {
    global $totalTests, $passedTests, $failedTests, $errors;
    $totalTests++;
    
    if ($condition) {
        $passedTests++;
        echo GREEN . "  ✓ " . RESET . "$message\n";
        return true;
    } else {
        $failedTests++;
        $errors[] = $message;
        echo RED . "  ✗ " . RESET . "$message\n";
        return false;
    }
}

function assert_false($condition, string $message): bool {
    return assert_true(!$condition, $message);
}

function assert_equals($expected, $actual, string $message): bool {
    return assert_true($expected === $actual, "$message (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")");
}

function assert_not_null($value, string $message): bool {
    return assert_true($value !== null, $message);
}

function assert_null($value, string $message): bool {
    return assert_true($value === null, $message);
}

function assert_contains($needle, array $haystack, string $message): bool {
    return assert_true(in_array($needle, $haystack), $message);
}

function assert_array_has_key($key, array $array, string $message): bool {
    return assert_true(array_key_exists($key, $array), $message);
}

function assert_not_empty($value, string $message): bool {
    return assert_true(!empty($value), $message);
}

function assert_greater_than($expected, $actual, string $message): bool {
    return assert_true($actual > $expected, "$message (expected > $expected, got $actual)");
}

function assert_instance_of(string $class, $object, string $message): bool {
    return assert_true($object instanceof $class, $message);
}

// ============================================================
// DATABASE CONNECTION TESTS
// ============================================================
echo YELLOW . "\n[Database Connection Tests]\n" . RESET;

try {
    $db = new Connection();
    assert_true(true, "Database connection established");
    
    $result = $db->query("SELECT 1 as test")->fetchAll();
    assert_equals(1, (int)$result[0]['test'], "Simple query works");
    
} catch (Exception $e) {
    assert_true(false, "Database connection failed: " . $e->getMessage());
    echo RED . "\n⚠ Cannot continue without database connection!\n" . RESET;
    exit(1);
}

// ============================================================
// TABLE STRUCTURE TESTS
// ============================================================
echo YELLOW . "\n[Table Structure Tests]\n" . RESET;

$tables = [
    'users' => ['id', 'uuid', 'email', 'username', 'password_hash', 'is_admin'],
    'teams' => ['id', 'uuid', 'name', 'personal_team'],
    'team_user' => ['team_id', 'user_id', 'role'],
    'nodes' => ['id', 'uuid', 'team_id', 'name', 'token', 'status'],
    'projects' => ['id', 'uuid', 'team_id', 'name'],
    'environments' => ['id', 'uuid', 'project_id', 'name'],
    'applications' => ['id', 'uuid', 'environment_id', 'name'],
    'templates' => ['id', 'uuid', 'name', 'slug', 'docker_compose'],
    'deployments' => ['id', 'uuid', 'application_id', 'status'],
    'sessions' => ['id', 'user_id'],
    'activity_logs' => ['id', 'action'],
];

foreach ($tables as $table => $expectedColumns) {
    try {
        $tableName = $table === 'databases' ? '`databases`' : $table;
        $columns = $db->query("DESCRIBE $tableName")->fetchAll();
        $columnNames = array_column($columns, 'Field');
        
        assert_not_empty($columns, "Table '$table' exists");
        
        foreach ($expectedColumns as $col) {
            assert_contains($col, $columnNames, "Table '$table' has column '$col'");
        }
    } catch (Exception $e) {
        assert_true(false, "Table '$table' check failed: " . $e->getMessage());
    }
}

// Test reserved word table (databases)
try {
    $columns = $db->query("DESCRIBE `databases`")->fetchAll();
    assert_not_empty($columns, "Table 'databases' (reserved word) exists");
} catch (Exception $e) {
    assert_true(false, "Table 'databases' check failed: " . $e->getMessage());
}

// ============================================================
// SEEDED DATA TESTS
// ============================================================
echo YELLOW . "\n[Seeded Data Tests]\n" . RESET;

try {
    $users = $db->query("SELECT * FROM users WHERE email = ?", ['admin@chap.dev'])->fetchAll();
    assert_not_empty($users, "Seeded user exists");
    assert_equals('admin@chap.dev', $users[0]['email'], "User email is correct");
    assert_equals('MJDawson', $users[0]['username'], "Username is correct");
    
    $teams = $db->query("SELECT * FROM teams")->fetchAll();
    assert_not_empty($teams, "At least one team exists");

    \Chap\Services\TemplateRegistry::syncToDatabase();

    $templates = $db->query("SELECT * FROM templates WHERE is_active = 1")->fetchAll();
    assert_not_empty($templates, "At least one active template exists");

    $slugs = array_column($templates, 'slug');
    assert_contains('minecraft-vanilla', $slugs, "Minecraft Vanilla template exists");
    
} catch (Exception $e) {
    assert_true(false, "Seeded data check failed: " . $e->getMessage());
}

// ============================================================
// MODEL TESTS
// ============================================================
echo YELLOW . "\n[Model Tests]\n" . RESET;

use Chap\Models\User;
use Chap\Models\Team;
use Chap\Models\Node;
use Chap\Models\Project;
use Chap\Models\Template;

try {
    // User model
    $user = User::findByEmail('admin@chap.dev');
    assert_not_null($user, "User::findByEmail works");
    assert_equals('admin@chap.dev', $user->email, "User email property works");
    
    // Password verification
    assert_true($user->verifyPassword('password'), "Password verification works for correct password");
    assert_false($user->verifyPassword('wrong'), "Password verification fails for wrong password");
    
    // User toArray
    $userArray = $user->toArray();
    assert_array_has_key('email', $userArray, "User toArray has email");
    assert_true(!isset($userArray['password_hash']), "User toArray excludes password_hash");
    
    // Template model
    \Chap\Services\TemplateRegistry::syncToDatabase();

    $templates = Template::where('is_active', true);
    assert_not_empty($templates, "Template::where('is_active', true) returns templates");

    $mc = Template::findBySlug('minecraft-vanilla');
    assert_not_null($mc, "Template::findBySlug works");
    assert_equals('Minecraft (Vanilla)', $mc->name, "Template name is correct");
    
} catch (Exception $e) {
    assert_true(false, "Model test failed: " . $e->getMessage());
}

// ============================================================
// NODE CRUD TESTS
// ============================================================
echo YELLOW . "\n[Node CRUD Tests]\n" . RESET;

try {
    $teams = $db->query("SELECT * FROM teams LIMIT 1")->fetchAll();
    $teamId = $teams[0]['id'];
    
    // Create
    $nodeName = 'test-node-' . time();
    $nodeToken = bin2hex(random_bytes(16));
    $node = Node::create([
        'team_id' => $teamId,
        'name' => $nodeName,
        'token' => $nodeToken,
        'status' => 'pending',
    ]);
    assert_not_null($node, "Node::create works");
    assert_not_empty($node->id, "Created node has ID");
    assert_not_empty($node->uuid, "Created node has UUID");
    
    // Read
    $foundNode = Node::find($node->id);
    assert_not_null($foundNode, "Node::find works");
    assert_equals($nodeName, $foundNode->name, "Found node has correct name");
    
    // Update
    $foundNode->update(['status' => 'online']);
    $updatedNode = Node::find($node->id);
    assert_equals('online', $updatedNode->status, "Node::update works");
    
    // ForTeam scope
    $teamNodes = Node::forTeam($teamId);
    $nodeIds = array_map(fn($n) => $n->id, $teamNodes);
    assert_contains($node->id, $nodeIds, "Node::forTeam scope works");
    
    // Delete
    $nodeId = $node->id;
    $node->delete();
    $deletedNode = Node::find($nodeId);
    assert_null($deletedNode, "Node::delete works");
    
} catch (Exception $e) {
    assert_true(false, "Node CRUD test failed: " . $e->getMessage());
}

// ============================================================
// PROJECT CRUD TESTS
// ============================================================
echo YELLOW . "\n[Project CRUD Tests]\n" . RESET;

try {
    $teams = $db->query("SELECT * FROM teams LIMIT 1")->fetchAll();
    $teamId = $teams[0]['id'];
    
    $projectName = 'Test Project ' . time();
    $project = Project::create([
        'team_id' => $teamId,
        'name' => $projectName,
        'description' => 'Test description',
    ]);
    assert_not_null($project, "Project::create works");
    assert_not_empty($project->uuid, "Created project has UUID");
    
    // Clean up
    $db->query("DELETE FROM projects WHERE id = ?", [$project->id]);
    assert_true(true, "Project cleanup successful");
    
} catch (Exception $e) {
    assert_true(false, "Project CRUD test failed: " . $e->getMessage());
}

// ============================================================
// AUTHENTICATION TESTS
// ============================================================
echo YELLOW . "\n[Authentication Tests]\n" . RESET;

use Chap\Auth\AuthManager;

$_SESSION = []; // Clear session

try {
    // Failed login
    $result = AuthManager::attempt('admin@chap.dev', 'wrong_password');
    assert_false($result, "Login fails with wrong password");
    assert_false(AuthManager::check(), "Not authenticated after failed login");
    
    // Successful login
    $result = AuthManager::attempt('admin@chap.dev', 'password');
    assert_true($result, "Login succeeds with correct password");
    assert_true(AuthManager::check(), "Authenticated after successful login");
    
    // Get user
    $user = AuthManager::user();
    assert_not_null($user, "AuthManager::user returns user after login");
    assert_equals('admin@chap.dev', $user->email, "Logged in user has correct email");
    
    // Logout
    AuthManager::logout();
    assert_false(AuthManager::check(), "Not authenticated after logout");
    assert_null(AuthManager::user(), "No user after logout");
    
} catch (Exception $e) {
    assert_true(false, "Authentication test failed: " . $e->getMessage());
}

// ============================================================
// HELPER FUNCTION TESTS
// ============================================================
echo YELLOW . "\n[Helper Function Tests]\n" . RESET;

try {
    // UUID
    $uuid1 = uuid();
    $uuid2 = uuid();
    assert_true(
        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid1) === 1,
        "uuid() generates valid UUID v4"
    );
    assert_true($uuid1 !== $uuid2, "uuid() generates unique values");
    
    // Token
    $token = generate_token(16);
    assert_equals(32, strlen($token), "generate_token(16) returns 32 hex chars");
    assert_true(preg_match('/^[0-9a-f]+$/i', $token) === 1, "generate_token returns only hex chars");
    
    // CSRF
    $_SESSION = [];
    $csrf = csrf_token();
    assert_not_empty($csrf, "csrf_token generates token");
    assert_equals($csrf, csrf_token(), "csrf_token returns same token on subsequent calls");
    assert_true(verify_csrf($csrf), "verify_csrf validates correct token");
    assert_false(verify_csrf('invalid'), "verify_csrf rejects invalid token");
    
    // Flash messages
    $_SESSION = [];
    flash('success', 'Test message');
    assert_not_null($_SESSION['_flash']['success'] ?? null, "flash() stores message");
    
    // e() function (escape HTML)
    assert_equals('&lt;script&gt;', e('<script>'), "e() escapes HTML");
    
} catch (Exception $e) {
    assert_true(false, "Helper function test failed: " . $e->getMessage());
}

// ============================================================
// ROUTER TESTS
// ============================================================
echo YELLOW . "\n[Router Tests]\n" . RESET;

use Chap\Router\Router;

try {
    $router = new Router();
    
    $router->get('/test', function() { return 'test'; });
    $router->post('/test', function() { return 'test'; });
    
    $routes = $router->getRoutes();
    assert_array_has_key('GET', $routes, "Router registers GET routes");
    assert_array_has_key('POST', $routes, "Router registers POST routes");
    
    // Route with parameters
    $router->get('/users/{id}', function($id) { return $id; });
    $routes = $router->getRoutes();
    $found = false;
    foreach ($routes['GET'] as $route) {
        if (strpos($route['pattern'], 'users') !== false) {
            $found = true;
            break;
        }
    }
    assert_true($found, "Router registers parameterized routes");
    
    // Route groups
    $router->group(['prefix' => '/api'], function($r) {
        $r->get('/items', function() { return 'items'; });
    });
    $routes = $router->getRoutes();
    $found = false;
    foreach ($routes['GET'] as $route) {
        if ($route['pattern'] === '/api/items') {
            $found = true;
            break;
        }
    }
    assert_true($found, "Router handles group prefixes");
    
} catch (Exception $e) {
    assert_true(false, "Router test failed: " . $e->getMessage());
}

// ============================================================
// FOREIGN KEY TESTS
// ============================================================
echo YELLOW . "\n[Foreign Key Constraint Tests]\n" . RESET;

try {
    // Nodes are global/unowned: nodes.team_id is nullable and not FK-constrained.
    $nodeColumns = $db->query("DESCRIBE nodes")->fetchAll();
    $teamIdCol = null;
    foreach ($nodeColumns as $col) {
        if (($col['Field'] ?? '') === 'team_id') {
            $teamIdCol = $col;
            break;
        }
    }
    assert_not_null($teamIdCol, "nodes.team_id column exists");
    if ($teamIdCol !== null) {
        assert_equals('YES', strtoupper((string)($teamIdCol['Null'] ?? '')), "nodes.team_id is nullable");
    }

    $nodesFk = $db->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'nodes'
            AND COLUMN_NAME = 'team_id'
            AND REFERENCED_TABLE_NAME = 'teams'
        ")->fetchAll();
    assert_true(empty($nodesFk), "No FK exists: nodes.team_id -> teams (nodes are global)");

    $fkTests = [
        ['projects', 'team_id', 'teams'],
        ['environments', 'project_id', 'projects'],
        ['applications', 'environment_id', 'environments'],
        ['team_user', 'team_id', 'teams'],
        ['team_user', 'user_id', 'users'],
    ];
    
    foreach ($fkTests as [$table, $column, $refTable]) {
        $fk = $db->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
            AND REFERENCED_TABLE_NAME = ?
        ", [$table, $column, $refTable])->fetchAll();
        
        assert_not_empty($fk, "FK exists: $table.$column -> $refTable");
    }
    
} catch (Exception $e) {
    assert_true(false, "Foreign key test failed: " . $e->getMessage());
}

// ============================================================
// RESULTS SUMMARY
// ============================================================
echo "\n" . BLUE . "═══════════════════════════════════════\n" . RESET;
echo BLUE . "           TEST RESULTS\n" . RESET;
echo BLUE . "═══════════════════════════════════════\n" . RESET;

echo "Total:  $totalTests\n";
echo GREEN . "Passed: $passedTests\n" . RESET;

if ($failedTests > 0) {
    echo RED . "Failed: $failedTests\n" . RESET;
    echo "\n" . RED . "Failed tests:\n" . RESET;
    foreach ($errors as $error) {
        echo RED . "  • $error\n" . RESET;
    }
    exit(1);
} else {
    echo "\n" . GREEN . "✓ All tests passed!\n" . RESET;
    exit(0);
}
