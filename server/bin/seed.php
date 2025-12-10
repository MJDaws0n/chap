#!/usr/bin/env php
<?php
/**
 * Database Seeder
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Database\Connection;
use Chap\Config;
use Chap\Auth\AuthManager;

// Load configuration
Config::load();

echo "Seeding Chap Database...\n\n";

try {
    $db = new Connection();
    
    // Check if already seeded
    $users = $db->fetch("SELECT COUNT(*) as count FROM users");
    if ($users && $users['count'] > 0) {
        echo "Database already seeded.\n";
        exit(0);
    }
    
    // Create admin user
    echo "Creating admin user (Max / MJDawson)...\n";
    
    $uuid = uuid();
    $passwordHash = AuthManager::hashPassword('password');
    
    $userId = $db->insert('users', [
        'uuid' => $uuid,
        'email' => 'max@chap.dev',
        'username' => 'MJDawson',
        'password_hash' => $passwordHash,
        'name' => 'Max',
        'is_admin' => true,
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);
    
    echo "Created user: MJDawson (email: max@chap.dev)\n";
    echo "Default password: password\n\n";
    
    // Create personal team
    echo "Creating personal team...\n";
    
    $teamId = $db->insert('teams', [
        'uuid' => uuid(),
        'name' => "MJDawson's Team",
        'description' => 'Personal team',
        'personal_team' => true,
    ]);
    
    $db->insert('team_user', [
        'team_id' => $teamId,
        'user_id' => $userId,
        'role' => 'owner',
    ]);
    
    echo "Created team: MJDawson's Team\n\n";
    
    // Seed some templates
    echo "Seeding application templates...\n";
    
    $templates = [
        [
            'name' => 'Nginx',
            'slug' => 'nginx',
            'description' => 'High-performance HTTP server and reverse proxy',
            'source_url' => 'https://nginx.org/en/docs/',
            'category' => 'Web Servers',
            'docker_compose' => "version: '3.8'\nservices:\n  nginx:\n    image: nginx:alpine\n    ports:\n      - '80:80'\n    volumes:\n      - ./html:/usr/share/nginx/html:ro",
            'ports' => json_encode([80]),
            'is_official' => true,
        ],
        [
            'name' => 'PostgreSQL',
            'slug' => 'postgresql',
            'description' => 'Powerful, open source object-relational database',
            'source_url' => 'https://www.postgresql.org/docs/',
            'category' => 'Databases',
            'docker_compose' => "version: '3.8'\nservices:\n  postgres:\n    image: postgres:15-alpine\n    environment:\n      POSTGRES_DB: \${DB_NAME:-app}\n      POSTGRES_USER: \${DB_USER:-postgres}\n      POSTGRES_PASSWORD: \${DB_PASSWORD:-secret}\n    volumes:\n      - postgres_data:/var/lib/postgresql/data\n    ports:\n      - '5432:5432'\nvolumes:\n  postgres_data:",
            'default_environment_variables' => json_encode([
                'DB_NAME' => 'app',
                'DB_USER' => 'postgres',
                'DB_PASSWORD' => 'secret',
            ]),
            'ports' => json_encode([5432]),
            'is_official' => true,
        ],
        [
            'name' => 'Redis',
            'slug' => 'redis',
            'description' => 'In-memory data structure store',
            'source_url' => 'https://redis.io/documentation',
            'category' => 'Databases',
            'docker_compose' => "version: '3.8'\nservices:\n  redis:\n    image: redis:alpine\n    ports:\n      - '6379:6379'\n    volumes:\n      - redis_data:/data\nvolumes:\n  redis_data:",
            'ports' => json_encode([6379]),
            'is_official' => true,
        ],
        [
            'name' => 'MySQL',
            'slug' => 'mysql',
            'description' => 'Popular open-source relational database',
            'source_url' => 'https://dev.mysql.com/doc/',
            'category' => 'Databases',
            'docker_compose' => "version: '3.8'\nservices:\n  mysql:\n    image: mysql:8.0\n    environment:\n      MYSQL_ROOT_PASSWORD: \${MYSQL_ROOT_PASSWORD:-root}\n      MYSQL_DATABASE: \${MYSQL_DATABASE:-app}\n      MYSQL_USER: \${MYSQL_USER:-user}\n      MYSQL_PASSWORD: \${MYSQL_PASSWORD:-secret}\n    volumes:\n      - mysql_data:/var/lib/mysql\n    ports:\n      - '3306:3306'\nvolumes:\n  mysql_data:",
            'default_environment_variables' => json_encode([
                'MYSQL_ROOT_PASSWORD' => 'root',
                'MYSQL_DATABASE' => 'app',
                'MYSQL_USER' => 'user',
                'MYSQL_PASSWORD' => 'secret',
            ]),
            'ports' => json_encode([3306]),
            'is_official' => true,
        ],
        [
            'name' => 'WordPress',
            'slug' => 'wordpress',
            'description' => 'Popular blogging and content management system',
            'source_url' => 'https://wordpress.org/documentation/',
            'category' => 'CMS',
            'docker_compose' => "version: '3.8'\nservices:\n  wordpress:\n    image: wordpress:latest\n    environment:\n      WORDPRESS_DB_HOST: db\n      WORDPRESS_DB_USER: \${DB_USER:-wordpress}\n      WORDPRESS_DB_PASSWORD: \${DB_PASSWORD:-secret}\n      WORDPRESS_DB_NAME: \${DB_NAME:-wordpress}\n    volumes:\n      - wordpress_data:/var/www/html\n    ports:\n      - '80:80'\n    depends_on:\n      - db\n  db:\n    image: mysql:8.0\n    environment:\n      MYSQL_DATABASE: \${DB_NAME:-wordpress}\n      MYSQL_USER: \${DB_USER:-wordpress}\n      MYSQL_PASSWORD: \${DB_PASSWORD:-secret}\n      MYSQL_RANDOM_ROOT_PASSWORD: 'yes'\n    volumes:\n      - db_data:/var/lib/mysql\nvolumes:\n  wordpress_data:\n  db_data:",
            'ports' => json_encode([80]),
            'is_official' => true,
        ],
        [
            'name' => 'Uptime Kuma',
            'slug' => 'uptime-kuma',
            'description' => 'Self-hosted monitoring tool',
            'source_url' => 'https://github.com/louislam/uptime-kuma',
            'category' => 'Monitoring',
            'docker_compose' => "version: '3.8'\nservices:\n  uptime-kuma:\n    image: louislam/uptime-kuma:1\n    volumes:\n      - uptime-kuma_data:/app/data\n    ports:\n      - '3001:3001'\n    restart: unless-stopped\nvolumes:\n  uptime-kuma_data:",
            'ports' => json_encode([3001]),
            'is_official' => true,
        ],
        [
            'name' => 'Grafana',
            'slug' => 'grafana',
            'description' => 'Open source analytics and monitoring solution',
            'source_url' => 'https://grafana.com/docs/',
            'category' => 'Monitoring',
            'docker_compose' => "version: '3.8'\nservices:\n  grafana:\n    image: grafana/grafana:latest\n    environment:\n      GF_SECURITY_ADMIN_PASSWORD: \${ADMIN_PASSWORD:-admin}\n    volumes:\n      - grafana_data:/var/lib/grafana\n    ports:\n      - '3000:3000'\nvolumes:\n  grafana_data:",
            'default_environment_variables' => json_encode([
                'ADMIN_PASSWORD' => 'admin',
            ]),
            'ports' => json_encode([3000]),
            'is_official' => true,
        ],
        [
            'name' => 'Portainer',
            'slug' => 'portainer',
            'description' => 'Docker management UI',
            'source_url' => 'https://docs.portainer.io/',
            'category' => 'Development',
            'docker_compose' => "version: '3.8'\nservices:\n  portainer:\n    image: portainer/portainer-ce:latest\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n      - portainer_data:/data\n    ports:\n      - '9000:9000'\n    restart: unless-stopped\nvolumes:\n  portainer_data:",
            'ports' => json_encode([9000]),
            'is_official' => true,
        ],
        [
            'name' => 'n8n',
            'slug' => 'n8n',
            'description' => 'Workflow automation tool',
            'source_url' => 'https://docs.n8n.io/',
            'category' => 'Automation',
            'docker_compose' => "version: '3.8'\nservices:\n  n8n:\n    image: n8nio/n8n:latest\n    environment:\n      N8N_BASIC_AUTH_ACTIVE: 'true'\n      N8N_BASIC_AUTH_USER: \${N8N_USER:-admin}\n      N8N_BASIC_AUTH_PASSWORD: \${N8N_PASSWORD:-changeme}\n    volumes:\n      - n8n_data:/home/node/.n8n\n    ports:\n      - '5678:5678'\nvolumes:\n  n8n_data:",
            'default_environment_variables' => json_encode([
                'N8N_USER' => 'admin',
                'N8N_PASSWORD' => 'changeme',
            ]),
            'ports' => json_encode([5678]),
            'is_official' => true,
        ],
    ];
    
    foreach ($templates as $template) {
        $template['uuid'] = uuid();
        $db->insert('templates', $template);
        echo "Created template: {$template['name']}\n";
    }
    
    echo "\nSeeding complete!\n";
    
} catch (Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
