# Chap - Design Document

## Overview

**Chap** is a self-hosted platform-as-a-service (PaaS) for deploying applications and managing servers. It provides a web interface for managing deployments, servers (called "nodes"), and services with Docker-based isolation.

**Author**: Max / MJDawson  
**Repository**: https://github.com/MJDaws0n/chap

---

## Architecture

### System Components

```
┌─────────────────────────────────────────────────────────────────┐
│                        CHAP ARCHITECTURE                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    CHAP SERVER                            │  │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐       │  │
│  │  │   Apache    │  │    PHP      │  │   MySQL     │       │  │
│  │  │  Webserver  │──│ Application │──│  Database   │       │  │
│  │  └─────────────┘  └─────────────┘  └─────────────┘       │  │
│  │         │                │                                │  │
│  │         │         ┌──────┴──────┐                        │  │
│  │         │         │  WebSocket  │                        │  │
│  │         │         │   Server    │                        │  │
│  │         │         │  (Ratchet)  │                        │  │
│  │         │         └──────┬──────┘                        │  │
│  └─────────┼────────────────┼───────────────────────────────┘  │
│            │                │                                   │
│            │                │ WebSocket                         │
│            │                │                                   │
│  ┌─────────┼────────────────┼───────────────────────────────┐  │
│  │         │                │         CHAP NODE             │  │
│  │         │         ┌──────┴──────┐                        │  │
│  │         │         │   Node.js   │                        │  │
│  │         │         │   Agent     │                        │  │
│  │         │         └──────┬──────┘                        │  │
│  │         │                │                                │  │
│  │         │         ┌──────┴──────┐                        │  │
│  │         │         │   Docker    │                        │  │
│  │         │         │   Engine    │                        │  │
│  │         │         └─────────────┘                        │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Docker Images

1. **chap-server** (PHP + Apache + MySQL connectivity)
   - Web UI (PHP templating)
   - REST API for management
   - WebSocket server for node communication
   - Job scheduler for background tasks
   - GitHub OAuth and webhook handling

2. **chap-node** (Node.js runtime)
   - WebSocket client connecting to server
   - Docker container management
   - Build execution (Dockerfile, docker-compose)
   - Log streaming
   - Health monitoring

---

## Data Model

### Entity Relationship Diagram

```
┌──────────┐       ┌──────────┐       ┌─────────────┐
│   User   │───────│   Team   │───────│   Project   │
└──────────┘  N:M  └──────────┘  1:N  └─────────────┘
     │                   │                   │
     │                   │                   │ 1:N
     │                   │            ┌──────┴──────┐
     │                   │            │ Environment │
     │                   │            └──────┬──────┘
     │                   │                   │ 1:N
     │                   │            ┌──────┴──────┐
     │                   │            │ Application │
     │                   │            └──────┬──────┘
     │                   │                   │ 1:N
     │                   │            ┌──────┴──────┐
     │                   │            │ Deployment  │
     │                   │            └─────────────┘
     │                   │
     │                   │ 1:N        ┌─────────────┐
     │                   └────────────│    Node     │
     │                                └──────┬──────┘
     │                                       │ 1:N
     │                                ┌──────┴──────┐
     │                                │  Container  │
     │                                └─────────────┘
     │
     │ 1:N            ┌─────────────┐
     └────────────────│   Session   │
                      └─────────────┘
```

### Database Schema

#### users
```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    avatar_url VARCHAR(500),
    github_id VARCHAR(100),
    github_token TEXT,
    is_admin BOOLEAN DEFAULT FALSE,
    email_verified_at TIMESTAMP NULL,
    two_factor_secret VARCHAR(255),
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### teams
```sql
CREATE TABLE teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    personal_team BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### team_user
```sql
CREATE TABLE team_user (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'admin', 'member') DEFAULT 'member',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_team_user (team_id, user_id)
);
```

#### nodes
```sql
CREATE TABLE nodes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    team_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    port INT DEFAULT 3000,
    status ENUM('pending', 'online', 'offline', 'error') DEFAULT 'pending',
    last_seen_at TIMESTAMP NULL,
    system_info JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);
```

#### projects
```sql
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    team_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);
```

#### environments
```sql
CREATE TABLE environments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    project_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
```

#### applications
```sql
CREATE TABLE applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    environment_id INT NOT NULL,
    node_id INT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    
    -- Git configuration
    git_repository VARCHAR(500),
    git_branch VARCHAR(255) DEFAULT 'main',
    git_commit_sha VARCHAR(40),
    
    -- Build configuration
    build_pack ENUM('dockerfile', 'docker-compose', 'nixpacks', 'static') DEFAULT 'dockerfile',
    dockerfile_path VARCHAR(500) DEFAULT 'Dockerfile',
    docker_compose_path VARCHAR(500) DEFAULT 'docker-compose.yml',
    build_context VARCHAR(500) DEFAULT '.',
    
    -- Runtime configuration
    port INT,
    domains TEXT,
    environment_variables JSON,
    build_args JSON,
    
    -- Resource limits
    memory_limit VARCHAR(20) DEFAULT '512m',
    cpu_limit VARCHAR(20) DEFAULT '1',
    
    -- Health check
    health_check_enabled BOOLEAN DEFAULT TRUE,
    health_check_path VARCHAR(255) DEFAULT '/',
    health_check_interval INT DEFAULT 30,
    
    -- State
    status ENUM('stopped', 'starting', 'running', 'error', 'deploying') DEFAULT 'stopped',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL
);
```

#### deployments
```sql
CREATE TABLE deployments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    application_id INT NOT NULL,
    node_id INT NOT NULL,
    
    -- Deployment details
    commit_sha VARCHAR(40),
    commit_message TEXT,
    
    -- Status tracking
    status ENUM('queued', 'building', 'deploying', 'running', 'failed', 'cancelled', 'rolled_back') DEFAULT 'queued',
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    
    -- Logs
    logs LONGTEXT,
    error_message TEXT,
    
    -- Rollback info
    rollback_of_id INT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (rollback_of_id) REFERENCES deployments(id) ON DELETE SET NULL
);
```

#### templates
```sql
CREATE TABLE templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    documentation_url VARCHAR(500),
    logo_url VARCHAR(500),
    category VARCHAR(100),
    tags JSON,
    
    -- Template content
    compose_content TEXT NOT NULL,
    environment_schema JSON,
    
    -- Metadata
    min_version VARCHAR(20),
    default_port INT,
    is_official BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### sessions
```sql
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT,
    last_activity INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### oauth_states
```sql
CREATE TABLE oauth_states (
    id INT PRIMARY KEY AUTO_INCREMENT,
    state VARCHAR(64) UNIQUE NOT NULL,
    provider VARCHAR(50) NOT NULL,
    redirect_url VARCHAR(500),
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### activity_logs
```sql
CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    team_id INT,
    subject_type VARCHAR(100),
    subject_id INT,
    action VARCHAR(100) NOT NULL,
    properties JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);
```

#### containers
```sql
CREATE TABLE containers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    node_id INT NOT NULL,
    deployment_id INT,
    container_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    image VARCHAR(500) NOT NULL,
    status ENUM('created', 'running', 'paused', 'restarting', 'exited', 'dead') DEFAULT 'created',
    ports JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE SET NULL
);
```

---

## WebSocket Protocol

### Connection Flow

```
Node                                    Server
  │                                        │
  │──────── Connect (token) ──────────────>│
  │                                        │
  │<─────── Auth Success/Fail ─────────────│
  │                                        │
  │──────── System Info ──────────────────>│
  │                                        │
  │<─────── Ack ───────────────────────────│
  │                                        │
  │         ... Heartbeat Loop ...         │
  │──────── Ping ─────────────────────────>│
  │<─────── Pong ──────────────────────────│
  │                                        │
  │         ... Task Assignment ...        │
  │<─────── Deploy Task ───────────────────│
  │──────── Task Ack ─────────────────────>│
  │──────── Log Stream ───────────────────>│
  │──────── Task Complete ────────────────>│
```

### Message Format

All messages are JSON with a `type` field:

```json
{
    "type": "message_type",
    "id": "unique_message_id",
    "timestamp": 1699876543,
    "payload": { ... }
}
```

### Message Types

#### Authentication

**node:auth** (Node → Server)
```json
{
    "type": "node:auth",
    "payload": {
        "token": "node_registration_token",
        "version": "1.0.0"
    }
}
```

**server:auth:success** (Server → Node)
```json
{
    "type": "server:auth:success",
    "payload": {
        "node_id": "uuid",
        "name": "node-1"
    }
}
```

**server:auth:failed** (Server → Node)
```json
{
    "type": "server:auth:failed",
    "payload": {
        "error": "Invalid token"
    }
}
```

#### System Information

**node:system_info** (Node → Server)
```json
{
    "type": "node:system_info",
    "payload": {
        "hostname": "node-1",
        "os": "linux",
        "arch": "x64",
        "cpus": 4,
        "memory_total": 8589934592,
        "memory_free": 4294967296,
        "docker_version": "24.0.0",
        "containers_running": 5,
        "containers_total": 10
    }
}
```

#### Heartbeat

**ping** (Node → Server)
```json
{
    "type": "ping",
    "payload": {
        "uptime": 3600,
        "load": [0.5, 0.4, 0.3]
    }
}
```

**pong** (Server → Node)
```json
{
    "type": "pong"
}
```

#### Deployment Tasks

**task:deploy** (Server → Node)
```json
{
    "type": "task:deploy",
    "payload": {
        "task_id": "uuid",
        "deployment_id": "uuid",
        "application": {
            "uuid": "uuid",
            "name": "my-app",
            "git_repository": "https://github.com/user/repo.git",
            "git_branch": "main",
            "build_pack": "dockerfile",
            "dockerfile_path": "Dockerfile",
            "environment_variables": {
                "NODE_ENV": "production"
            },
            "port": 3000,
            "memory_limit": "512m",
            "cpu_limit": "1"
        }
    }
}
```

**task:ack** (Node → Server)
```json
{
    "type": "task:ack",
    "payload": {
        "task_id": "uuid",
        "status": "accepted"
    }
}
```

**task:log** (Node → Server)
```json
{
    "type": "task:log",
    "payload": {
        "task_id": "uuid",
        "deployment_id": "uuid",
        "log_type": "stdout",
        "message": "Building image...",
        "timestamp": 1699876543
    }
}
```

**task:complete** (Node → Server)
```json
{
    "type": "task:complete",
    "payload": {
        "task_id": "uuid",
        "deployment_id": "uuid",
        "status": "success",
        "container_id": "abc123",
        "ports": {"3000/tcp": 32768}
    }
}
```

**task:failed** (Node → Server)
```json
{
    "type": "task:failed",
    "payload": {
        "task_id": "uuid",
        "deployment_id": "uuid",
        "error": "Build failed: Dockerfile not found"
    }
}
```

#### Container Management

**container:list** (Server → Node)
```json
{
    "type": "container:list",
    "payload": {
        "request_id": "uuid"
    }
}
```

**container:list:response** (Node → Server)
```json
{
    "type": "container:list:response",
    "payload": {
        "request_id": "uuid",
        "containers": [
            {
                "id": "abc123",
                "name": "my-app",
                "image": "my-app:latest",
                "status": "running",
                "ports": {"3000/tcp": 32768}
            }
        ]
    }
}
```

**container:stop** (Server → Node)
```json
{
    "type": "container:stop",
    "payload": {
        "container_id": "abc123"
    }
}
```

**container:logs** (Server → Node)
```json
{
    "type": "container:logs",
    "payload": {
        "container_id": "abc123",
        "follow": true,
        "tail": 100
    }
}
```

**container:logs:stream** (Node → Server)
```json
{
    "type": "container:logs:stream",
    "payload": {
        "container_id": "abc123",
        "log_type": "stdout",
        "message": "Server started on port 3000"
    }
}
```

---

## Storage Architecture

### Server Storage

The server container stores minimal data:

```
/var/www/html/storage/
├── cache/           # Application cache
├── logs/            # PHP application logs
├── sessions/        # Session files (if file-based)
└── framework/       # Framework cache (views, routes)
```

### Node Storage

Each node maintains persistent storage for deployments:

```
/data/
├── apps/            # Per-application runtime data
│   └── {app_id}/
│       ├── current/ # Current deployment symlink
│       └── config/  # Application configuration
│
├── repos/           # Git repository clones (cached for fast rebuilds)
│   └── {app_id}/
│
├── builds/          # Build contexts and artifacts
│   └── {app_id}/
│       └── {deployment_id}/
│
├── compose/         # Docker Compose files
│   └── {app_id}/
│       ├── docker-compose.yml
│       └── .env
│
├── volumes/         # Persistent volumes for containers
│   └── {app_id}/
│       └── data/    # Application data (databases, uploads, etc.)
│
└── logs/            # Build and deployment logs
    └── {app_id}/
        └── {deployment_id}.log
```

### Multi-Node Architecture

Each node is independent and maintains its own storage:

```
┌──────────────────────────────────────────────────────────────────┐
│                        CHAP SERVER                                │
│                    (MySQL + WebSocket Hub)                        │
└──────────────────┬────────────────┬───────────────┬──────────────┘
                   │                │               │
          WebSocket│       WebSocket│      WebSocket│
                   │                │               │
          ┌────────▼───────┐ ┌──────▼────────┐ ┌────▼──────────┐
          │   NODE-1       │ │   NODE-2      │ │   NODE-3      │
          │ ┌────────────┐ │ │ ┌───────────┐ │ │ ┌───────────┐ │
          │ │  /data     │ │ │ │  /data    │ │ │ │  /data    │ │
          │ │  - apps    │ │ │ │  - apps   │ │ │ │  - apps   │ │
          │ │  - repos   │ │ │ │  - repos  │ │ │ │  - repos  │ │
          │ │  - builds  │ │ │ │  - builds │ │ │ │  - builds │ │
          │ │  - volumes │ │ │ │  - volumes│ │ │ │  - volumes│ │
          │ └────────────┘ │ │ └───────────┘ │ │ └───────────┘ │
          │                │ │               │ │               │
          │  Docker        │ │  Docker       │ │  Docker       │
          │  Containers    │ │  Containers   │ │  Containers   │
          └────────────────┘ └───────────────┘ └───────────────┘
```

- **Server knows all nodes**: The `nodes` table tracks all registered nodes
- **Each node is independent**: Applications deploy to a specific node
- **No shared state**: Nodes don't communicate with each other
- **Volumes are node-local**: Container data stays on the node where it runs

---

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register new user |
| POST | `/api/auth/login` | Login user |
| POST | `/api/auth/logout` | Logout user |
| POST | `/api/auth/forgot-password` | Request password reset |
| POST | `/api/auth/reset-password` | Reset password |
| GET | `/api/auth/github` | Initiate GitHub OAuth |
| GET | `/api/auth/github/callback` | GitHub OAuth callback |
| GET | `/api/auth/me` | Get current user |

### Teams

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/teams` | List user's teams |
| POST | `/api/teams` | Create team |
| GET | `/api/teams/{uuid}` | Get team |
| PUT | `/api/teams/{uuid}` | Update team |
| DELETE | `/api/teams/{uuid}` | Delete team |
| POST | `/api/teams/{uuid}/members` | Add member |
| DELETE | `/api/teams/{uuid}/members/{user_uuid}` | Remove member |

### Nodes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/nodes` | List nodes |
| POST | `/api/nodes` | Create node |
| GET | `/api/nodes/{uuid}` | Get node |
| PUT | `/api/nodes/{uuid}` | Update node |
| DELETE | `/api/nodes/{uuid}` | Delete node |
| POST | `/api/nodes/{uuid}/regenerate-token` | Regenerate token |

### Projects

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/projects` | List projects |
| POST | `/api/projects` | Create project |
| GET | `/api/projects/{uuid}` | Get project |
| PUT | `/api/projects/{uuid}` | Update project |
| DELETE | `/api/projects/{uuid}` | Delete project |

### Environments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/projects/{project_uuid}/environments` | List environments |
| POST | `/api/projects/{project_uuid}/environments` | Create environment |
| GET | `/api/environments/{uuid}` | Get environment |
| PUT | `/api/environments/{uuid}` | Update environment |
| DELETE | `/api/environments/{uuid}` | Delete environment |

### Applications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/environments/{env_uuid}/applications` | List applications |
| POST | `/api/environments/{env_uuid}/applications` | Create application |
| GET | `/api/applications/{uuid}` | Get application |
| PUT | `/api/applications/{uuid}` | Update application |
| DELETE | `/api/applications/{uuid}` | Delete application |
| POST | `/api/applications/{uuid}/deploy` | Trigger deployment |
| POST | `/api/applications/{uuid}/stop` | Stop application |
| POST | `/api/applications/{uuid}/restart` | Restart application |

### Deployments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/applications/{app_uuid}/deployments` | List deployments |
| GET | `/api/deployments/{uuid}` | Get deployment |
| GET | `/api/deployments/{uuid}/logs` | Get deployment logs |
| POST | `/api/deployments/{uuid}/cancel` | Cancel deployment |
| POST | `/api/deployments/{uuid}/rollback` | Rollback to deployment |

### Templates

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/templates` | List templates |
| GET | `/api/templates/{slug}` | Get template |
| POST | `/api/templates/{slug}/deploy` | Deploy template |

### Webhooks

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/webhooks/github` | GitHub webhook handler |
| POST | `/webhooks/gitlab` | GitLab webhook handler |

### Internal (Node Communication)

| Method | Endpoint | Description |
|--------|----------|-------------|
| WS | `/ws/node` | WebSocket endpoint for nodes |

---

## File Structure

```
chap/
├── design.md                    # This document
├── docker-compose.yml           # Development orchestration
├── .env.example                 # Environment template
│
├── server/                      # PHP Server Application
│   ├── Dockerfile
│   ├── apache.conf
│   ├── composer.json
│   │
│   ├── public/                  # Web root
│   │   ├── index.php            # Entry point
│   │   ├── .htaccess
│   │   └── assets/
│   │       ├── css/
│   │       ├── js/
│   │       └── images/
│   │
│   ├── src/                     # Application source
│   │   ├── App.php              # Main application class
│   │   ├── Config.php           # Configuration
│   │   │
│   │   ├── Auth/                # Authentication
│   │   │   ├── AuthManager.php
│   │   │   ├── Session.php
│   │   │   ├── OAuth/
│   │   │   │   ├── GitHubProvider.php
│   │   │   │   └── OAuthManager.php
│   │   │   └── TwoFactor.php
│   │   │
│   │   ├── Controllers/         # HTTP Controllers
│   │   │   ├── BaseController.php
│   │   │   ├── AuthController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── TeamController.php
│   │   │   ├── NodeController.php
│   │   │   ├── ProjectController.php
│   │   │   ├── EnvironmentController.php
│   │   │   ├── ApplicationController.php
│   │   │   ├── DeploymentController.php
│   │   │   ├── TemplateController.php
│   │   │   └── WebhookController.php
│   │   │
│   │   ├── Models/              # Data models
│   │   │   ├── BaseModel.php
│   │   │   ├── User.php
│   │   │   ├── Team.php
│   │   │   ├── Node.php
│   │   │   ├── Project.php
│   │   │   ├── Environment.php
│   │   │   ├── Application.php
│   │   │   ├── Deployment.php
│   │   │   ├── Template.php
│   │   │   ├── Container.php
│   │   │   └── ActivityLog.php
│   │   │
│   │   ├── Database/            # Database layer
│   │   │   ├── Connection.php
│   │   │   ├── QueryBuilder.php
│   │   │   └── Migration.php
│   │   │
│   │   ├── Router/              # Routing
│   │   │   ├── Router.php
│   │   │   └── Route.php
│   │   │
│   │   ├── WebSocket/           # WebSocket server
│   │   │   ├── Server.php
│   │   │   ├── NodeHandler.php
│   │   │   └── MessageHandler.php
│   │   │
│   │   ├── Services/            # Business logic
│   │   │   ├── DeploymentService.php
│   │   │   ├── NodeManager.php
│   │   │   ├── TemplateService.php
│   │   │   └── GitService.php
│   │   │
│   │   ├── Middleware/          # HTTP middleware
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── CsrfMiddleware.php
│   │   │   └── TeamMiddleware.php
│   │   │
│   │   ├── Helpers/             # Utility functions
│   │   │   ├── functions.php
│   │   │   └── Validator.php
│   │   │
│   │   └── Views/               # View templates
│   │       ├── layouts/
│   │       │   └── main.php
│   │       ├── auth/
│   │       │   ├── login.php
│   │       │   └── register.php
│   │       ├── dashboard/
│   │       │   └── index.php
│   │       ├── nodes/
│   │       ├── projects/
│   │       ├── applications/
│   │       └── templates/
│   │
│   ├── migrations/              # Database migrations
│   │   ├── 001_create_users.php
│   │   ├── 002_create_teams.php
│   │   └── ...
│   │
│   ├── tests/                   # Server tests
│   │   ├── AuthTest.php
│   │   ├── NodeTest.php
│   │   └── DeploymentTest.php
│   │
│   └── bin/                     # CLI scripts
│       ├── migrate.php
│       ├── seed.php
│       └── websocket.php
│
├── node/                        # Node.js Agent
│   ├── Dockerfile
│   ├── package.json
│   │
│   ├── src/
│   │   ├── index.js             # Entry point
│   │   ├── config.js            # Configuration
│   │   │
│   │   ├── ws/                  # WebSocket client
│   │   │   ├── client.js
│   │   │   └── handlers.js
│   │   │
│   │   ├── docker/              # Docker management
│   │   │   ├── manager.js
│   │   │   ├── build.js
│   │   │   └── logs.js
│   │   │
│   │   ├── tasks/               # Task execution
│   │   │   ├── runner.js
│   │   │   ├── deploy.js
│   │   │   └── container.js
│   │   │
│   │   └── utils/               # Utilities
│   │       ├── system.js
│   │       └── logger.js
│   │
│   └── tests/                   # Node tests
│       ├── docker.test.js
│       └── ws.test.js
│
├── templates/                   # Application templates
│   └── services.json            # Template definitions
│
└── docs/                        # Documentation
    ├── README.md
    ├── SETUP.md
    ├── SECURITY.md
    └── API.md
```

---

## Security Considerations

### Authentication
- Passwords hashed with bcrypt (cost 12)
- Session tokens are cryptographically random (32 bytes)
- Sessions stored server-side in database
- CSRF tokens on all state-changing forms
- Rate limiting on login attempts

### Node Security
- Each node has unique authentication token
- Token is single-use during registration
- Node messages are validated against schema
- Nodes cannot access other team's resources
- Container isolation via Docker

### Input Validation
- All user input sanitized
- SQL queries use prepared statements
- XSS protection via output escaping
- File uploads validated (type, size, content)

### Communication
- Server-Node communication over WebSocket
- TLS recommended in production
- Message integrity via HMAC (optional)

---

## Color Theme (from screenshots)

```css
:root {
    /* Primary */
    --primary: #6366f1;         /* Indigo */
    --primary-hover: #4f46e5;
    
    /* Background */
    --bg-primary: #0f172a;      /* Dark blue-gray */
    --bg-secondary: #1e293b;
    --bg-tertiary: #334155;
    
    /* Text */
    --text-primary: #f8fafc;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    
    /* Status */
    --success: #22c55e;
    --warning: #f59e0b;
    --error: #ef4444;
    --info: #3b82f6;
    
    /* Borders */
    --border: #334155;
}
```

---

## Commit: design.md - Initial architecture and design document

Created comprehensive design document including:
- System architecture overview with two Docker images (chap-server, chap-node)
- Complete database schema with all entities and relationships
- WebSocket protocol specification for node communication
- API endpoint documentation
- File structure layout
- Security considerations
- Color theme reference
