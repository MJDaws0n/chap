# Chap

**Chap** is a self-hosted deployment platform inspired by Coolify, designed to give you full control over your applications, databases, and services on your own infrastructure.

## Features

- 🚀 **Git-based Deployments** - Connect GitHub, GitLab, or Bitbucket repositories and deploy on every push
- 🔄 **Auto Deployments** - Webhook-triggered deployments with rollback support
- 📦 **Docker Native** - All applications run in containers for consistency and isolation
- 🖥️ **Multi-Server Support** - Connect and manage multiple servers from a single dashboard
- 👥 **Team Collaboration** - Role-based access control with team workspaces
- 📊 **Real-time Monitoring** - Live logs and container metrics
- 🎯 **One-Click Services** - Deploy databases and popular services instantly
- 🔒 **Secure by Design** - Environment variables, secrets management, and encrypted SSH keys

## Architecture

Chap consists of two components that run on **separate servers**:

| Component | Where it runs | What it does |
|-----------|--------------|--------------|
| **chap-server** | One central server | Web UI, API, database, WebSocket hub |
| **chap-node** | Each deployment server | Docker management, builds, deployments |

```
┌─────────────────────────────────────────────────────────────┐
│                  YOUR CENTRAL SERVER                         │
│  ┌────────────────────────────────────────────────────────┐ │
│  │                    Chap Server                          │ │
│  │  Apache/PHP  ◄──►  MySQL  ◄──►  WebSocket Hub          │ │
│  └────────────────────────────────────────────────────────┘ │
└──────────────────────────┬──────────────────────────────────┘
                           │ WebSocket (port 8081)
           ┌───────────────┼───────────────┐
           │               │               │
           ▼               ▼               ▼
┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
│  SERVER A        │ │  SERVER B        │ │  SERVER C        │
│  ┌────────────┐  │ │  ┌────────────┐  │ │  ┌────────────┐  │
│  │ Chap Node  │  │ │  │ Chap Node  │  │ │  │ Chap Node  │  │
│  │  + Docker  │  │ │  │  + Docker  │  │ │  │  + Docker  │  │
│  └────────────┘  │ │  └────────────┘  │ │  └────────────┘  │
│  Your apps here  │ │  Your apps here  │ │  Your apps here  │
└──────────────────┘ └──────────────────┘ └──────────────────┘
```

---

## Installation

### Prerequisites

- Docker and Docker Compose installed on all servers
- Network connectivity between server and nodes (port 8081)

---

## Step 1: Install the Server

Run this on your **central server** (where you want the dashboard):

```bash
# Clone the repository
git clone https://github.com/MJDaws0n/chap.git
cd chap

# Copy and edit environment file
cp .env.example .env
nano .env  # Edit with your settings

# Start the server
docker compose -f docker-compose.server.yml up -d
```

Access the dashboard at `http://your-server:8080`

Default login:
- Email: `max@chap.dev`
- Password: `password`

**⚠️ Change the default password immediately!**

---

## Step 2: Install Nodes

Run this on **each server** where you want to deploy applications.

> **Note:** You only run ONE node per server. Each node connects back to your central Chap server.

### Option A: Quick Install (Recommended)

```bash
# Download just the node compose file
curl -O https://raw.githubusercontent.com/MJDaws0n/chap/main/docker-compose.node.yml

# Create environment file
cat > .env << EOF
NODE_ID=my-server-name
NODE_TOKEN=your-token-from-dashboard
CHAP_SERVER_URL=ws://your-chap-server:8081
EOF

# Start the node
docker compose -f docker-compose.node.yml up -d
```

### Option B: Single Docker Command

```bash
docker run -d \
  --name chap-node \
  --restart unless-stopped \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v chap_data:/data \
  -e NODE_ID=my-server-name \
  -e NODE_TOKEN=your-token-from-dashboard \
  -e CHAP_SERVER_URL=ws://your-chap-server:8081 \
  ghcr.io/mjdaws0n/chap-node:latest
```

### Getting Node Credentials

1. Log in to your Chap dashboard
2. Go to **Nodes** → **Add Node**
3. Copy the generated `NODE_ID` and `NODE_TOKEN`
4. Use these in your node's environment variables

---

## Development Setup

For local development (runs server + node on same machine):

```bash
git clone https://github.com/MJDaws0n/chap.git
cd chap
cp .env.example .env
docker compose up -d
```

This starts everything locally at `http://localhost:8080`

---

## Docker Compose Files

| File | Purpose | When to use |
|------|---------|-------------|
| `docker-compose.yml` | Development | Local testing (server + node together) |
| `docker-compose.server.yml` | Production server | Your central/main server |
| `docker-compose.node.yml` | Production node | Each deployment server |

---

## Deploying Applications

### From Git Repository

1. Create a **Project** to organize your applications
2. Create an **Environment** (e.g., production, staging)
3. Add a new **Application**
4. Connect your Git repository
5. Configure build settings (Dockerfile, build commands)
6. Select which **Node** to deploy to
7. Click **Deploy**

### From Docker Image

1. Create a Project and Environment
2. Add a new Application → Select "Docker Image"
3. Enter the image name (e.g., `nginx:latest`)
4. Configure ports and environment variables
5. Click **Deploy**

### One-Click Services

1. Go to **Templates**
2. Browse available services (PostgreSQL, MySQL, Redis, etc.)
3. Click **Deploy** and select a Node
4. Configure and launch

---

## API

```bash
# Login and get token
curl -X POST https://your-chap-server/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "your@email.com", "password": "your-password"}'

# Trigger deployment
curl -X POST https://your-chap-server/api/v1/applications/{id}/deploy \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Configuration

### Server Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_URL` | Public URL of your Chap server | `http://localhost:8080` |
| `APP_SECRET` | Encryption key (min 32 chars) | (required) |
| `DB_PASSWORD` | MySQL password | (required) |
| `GITHUB_CLIENT_ID` | For GitHub OAuth | (optional) |
| `GITHUB_CLIENT_SECRET` | For GitHub OAuth | (optional) |

### Node Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `NODE_ID` | Unique name for this node | (required) |
| `NODE_TOKEN` | Auth token from dashboard | (required) |
| `CHAP_SERVER_URL` | WebSocket URL to server | (required) |
| `CHAP_DATA_DIR` | Storage path | `/data` |

---

## Project Structure

```
chap/
├── server/                     # PHP server application
│   ├── public/                # Web root
│   ├── src/                   # Application code
│   └── migrations/            # Database migrations
├── node/                       # Node.js agent
│   └── src/                   # Agent code
├── docker-compose.yml          # Development (all-in-one)
├── docker-compose.server.yml   # Production server only
└── docker-compose.node.yml     # Production node only
```

---

## License

MIT License - see [LICENSE](LICENSE)

## Credits

Created by Max

Inspired by [Coolify](https://coolify.io/)
