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
┌─────────────────────────────────────────────────────────────────┐
│                        BROWSER CLIENT                           │
│                  (Dashboard / Live Logs UI)                     │ ───┐
└───────────────────────────────┬─────────────────────────────────┘    │
                                │ HTTP / HTTPS                         │
                                ▼                                      │
┌─────────────────────────────────────────────────────────────────┐    │
│                        CENTRAL SERVER                           │    │
│  ┌───────────────────────────────────────────────────────────┐  │    │
│  │                        Chap Server                        │  │    │
│  │     Apache / PHP  ◄──►  MySQL                             │  │    │
│  │     (auth, state, UI API, metadata)                       │  │    │
│  └───────────────────────────────────────────────────────────┘  │    │
└───────────────────────────────┬─────────────────────────────────┘    │
                                │ WebSocket (port 8081)                │
           ┌────────────────────┼────────────────────────┐             │
           │                    │                        │             │
           ▼                    ▼                        ▼             │
┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐         │
│  SERVER A        │ │  SERVER B        │ │  SERVER C        │         │
│  ┌────────────┐  │ │  ┌────────────┐  │ │  ┌────────────┐  │         │
│  │ Chap Node  │  │ │  │ Chap Node  │  │ │  │ Chap Node  │  │         │
│  │  + Docker  │  │ │  │  + Docker  │  │ │  │  + Docker  │  │         │
│  └────────────┘  │ │  └────────────┘  │ │  └────────────┘  │         │
│  Your apps here  │ │  Your apps here  │ │  Your apps here  │         │
└──────────────────┘ └──────────────────┘ └──────────────────┘         │
         ▲                    ▲                     ▲                  │
         └──── Optional Direct WebSocket (logs) ────┴──────────────────┘
```
---

## Installation

### Prerequisites

- Docker and Docker Compose installed on all servers
- Network connectivity between server and nodes (port 8081 on central server)

---

## Step 1: Install the Server

Run this on your **central server** (where you want the dashboard):

```bash
# Clone the repository
git clone https://github.com/MJDaws0n/chap.git
cd chap

# Remove the node (if not running node on this server)
rm -r node
rm docker-compose.node.yml

# Remove development file (can create confusion)
rm docker-compose.yml

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

### Quick Install

```bash
# Clone the repository
git clone https://github.com/MJDaws0n/chap.git
cd chap

# Remove the server (web inferface not needed)
rm -r node
rm docker-compose.server.yml

# Remove development file (can create confusion)
rm docker-compose.yml

# Create environment file from the example (in node folder) and edit it
cp node/.env.example .env
nano .env  # set NODE_ID, NODE_TOKEN and CHAP_SERVER_URL (see notes below)

# Start the node
docker compose -f docker-compose.node.yml up -d
```

Note: when setting `CHAP_SERVER_URL` for a node, include the WebSocket scheme. Use `wss://` if your Chap server is served over HTTPS (TLS), or `ws://` for non-HTTPS setups. See the "Live Logging (WebSocket)" section below for certificate and reverse-proxy details.

### Getting Node Credentials

1. Log in to your Chap dashboard
2. Go to **Nodes** → **Add Node**
3. Create a name for your node and copy the `NODE_TOKEN`
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

### One-Click Services

1. Go to **Templates**
2. Browse available services (PostgreSQL, MySQL, Redis, etc.)
3. Click **Deploy** and select a Node
4. Configure and launch

---

## API (not implemented yet)

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

### Live Logging (WebSocket)

Chap supports optional live logging using a persistent WebSocket connection between each node and the central server. This enables near-real-time application logs in the dashboard.

- **Node URL scheme:** When creating a node, set `CHAP_SERVER_URL` to include the scheme: use `wss://your-chap-server:8081` for HTTPS servers, or `ws://your-chap-server:8081` for non-HTTPS servers.
- **Certificates & TLS:** If your dashboard is served over HTTPS, browsers require a secure WebSocket (`wss://`) with a valid certificate. Provide a valid certificate either by:
  - Terminating TLS at your reverse proxy (recommended) and proxying WebSocket traffic to the Chap server, or
  - Setting the certificate values in your server's `.env` (uncomment and fill the certificate variables). If those `.env` certificate variables remain commented out the node will fall back to `ws://` (insecure).
- **Mixed-content warning:** If the dashboard uses HTTPS but the WebSocket uses `ws://`, modern browsers will block the connection as mixed content. To avoid this, either use `wss://` with a valid cert or terminate TLS at a reverse proxy.
- **Fallback behavior:** The WebSocket is used only for live application logging. If WebSocket connectivity isn't available, logs still work by polling over HTTP, but expect higher latency and increased resource usage.
---

## License
MIT License - see [LICENSE](LICENSE)

## Credits
Created by Max

Inspired by [Coolify](https://coolify.io/)
