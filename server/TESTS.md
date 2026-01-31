# Chap Test Suite

A comprehensive test suite for the Chap application covering database schema, models, authentication, helper functions, routing, and foreign key constraints.

## Running Tests

```bash
# From host machine (tests run inside container)
docker exec chap-server php /var/www/html/bin/test.php

# Or from inside the container
php /var/www/html/bin/test.php
```

## Test Categories

### 1. Database Connection Tests (2 tests)
- Verifies database connectivity
- Tests basic query execution

### 2. Table Structure Tests (56 tests)
Validates that all required tables and columns exist:

| Table | Columns Verified |
|-------|------------------|
| `users` | id, uuid, email, username, password_hash, is_admin |
| `teams` | id, uuid, name, personal_team |
| `team_user` | team_id, user_id, role |
| `nodes` | id, uuid, team_id, name, token, status |
| `projects` | id, uuid, team_id, name |
| `environments` | id, uuid, project_id, name |
| `applications` | id, uuid, environment_id, name |
| `templates` | id, uuid, name, slug, docker_compose |
| `deployments` | id, uuid, application_id, status |
| `sessions` | id, user_id |
| `activity_logs` | id, action |
| `databases` | (reserved word table - tests backtick handling) |

### 3. Seeded Data Tests (8 tests)
Verifies that the seed script creates:
- Default admin user (`admin@chap.dev` / `MJDawson`)
- At least one team
- At least 5 official templates (Nginx, MySQL, PostgreSQL, etc.)

### 4. Model Tests (9 tests)
Tests model functionality:

**User Model:**
- `findByEmail()` - Find user by email address
- Property access (email)
- `verifyPassword()` - Password hash verification
- `toArray()` - Serialization (includes email, excludes password_hash)

**Template Model:**
- `all()` - Retrieve all templates
- `findBySlug()` - Find template by slug
- Property access

### 5. Node CRUD Tests (8 tests)
Full CRUD cycle for Node model:
- **Create**: `Node::create()` with UUID generation
- **Read**: `Node::find()` by ID
- **Update**: `Node::update()` status changes
- **Scoped Query**: `Node::forTeam()` scope
- **Delete**: `Node::delete()`

### 6. Project CRUD Tests (3 tests)
- Create project with team association
- UUID generation verification
- Cleanup/delete functionality

### 7. Authentication Tests (8 tests)
Tests `AuthManager` class:
- Failed login (wrong password)
- Authentication state after failed login
- Successful login
- Authentication state after successful login
- `AuthManager::user()` retrieval
- User properties after login
- Logout functionality
- Authentication state after logout

### 8. Helper Function Tests (10 tests)
Tests global helper functions:

| Function | Tests |
|----------|-------|
| `uuid()` | Valid UUID v4 format, uniqueness |
| `generate_token()` | Correct length, hex characters only |
| `csrf_token()` | Token generation, consistency |
| `verify_csrf()` | Valid token acceptance, invalid token rejection |
| `flash()` | Message storage in session |
| `e()` | HTML entity escaping |

### 9. Router Tests (4 tests)
Tests `Router` class:
- GET route registration
- POST route registration
- Parameterized route handling (`/users/{id}`)
- Route group prefixing (`/api/items`)

### 10. Foreign Key Constraint Tests (6 tests)
Verifies database referential integrity:

| Foreign Key | Parent Table |
|-------------|--------------|
| `nodes.team_id` | `teams` |
| `projects.team_id` | `teams` |
| `environments.project_id` | `projects` |
| `applications.environment_id` | `environments` |
| `team_user.team_id` | `teams` |
| `team_user.user_id` | `users` |

## Test Output

Tests provide colored output:
- 🟢 **Green**: Passed tests
- 🔴 **Red**: Failed tests
- 🟡 **Yellow**: Section headers
- 🔵 **Blue**: Summary information

## Test Results Summary

```
Total:  114
Passed: 114

✓ All tests passed!
```

## Adding New Tests

Tests are written in `bin/test.php` using simple assertion functions:

```php
// Basic assertions
assert_true($condition, "Description");
assert_false($condition, "Description");
assert_equals($expected, $actual, "Description");
assert_not_null($value, "Description");
assert_not_empty($value, "Description");
assert_contains($needle, $haystack, "Description");
assert_array_has_key($key, $array, "Description");
assert_greater_than($expected, $actual, "Description");
```

### Example Test

```php
try {
    $user = User::findByEmail('test@example.com');
    assert_not_null($user, "User found by email");
    assert_equals('test@example.com', $user->email, "Email matches");
} catch (Exception $e) {
    assert_true(false, "Test failed: " . $e->getMessage());
}
```

## Known Warnings

The following warnings appear during testing but don't affect functionality:

1. **session_regenerate_id() warning** - Occurs when testing AuthManager outside of HTTP context (no active session). This is expected behavior in CLI testing.

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Running Docker containers (`chap-server`, `chap-mysql`)
- Seeded database (`php bin/seed.php`)
