<?php
/**
 * PHPUnit Bootstrap File
 * 
 * Sets up the testing environment before running tests.
 */

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';

// Load test environment
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';
$_ENV['APP_SECRET'] = 'test_secret_key_for_testing_only_32chars';
$_ENV['DB_HOST'] = getenv('DB_HOST') ?: 'localhost';
$_ENV['DB_PORT'] = getenv('DB_PORT') ?: '3306';
$_ENV['DB_DATABASE'] = getenv('DB_DATABASE') ?: 'chap_test';
$_ENV['DB_USERNAME'] = getenv('DB_USERNAME') ?: 'chap';
$_ENV['DB_PASSWORD'] = getenv('DB_PASSWORD') ?: 'chap_secret';

// Set environment variables
foreach ($_ENV as $key => $value) {
    putenv("$key=$value");
}

// Start session for tests that need it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "Test environment loaded.\n";
echo "Database: {$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}/{$_ENV['DB_DATABASE']}\n\n";
