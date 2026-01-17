# Chap Client API (Proposed) — Server + Node

**Status**: Design specification (API not fully implemented).  
**Audience**: External clients (CLI, SDKs, automation).  
**Non-goals**: End-user account creation, password resets, admin-only user management.

Chap is a **two-plane system**:

- **Server API**: source of truth (teams, projects, environments, apps, deployments metadata, templates, audit logs, integrations). Also mints short-lived **node access tokens**.
- **Node API**: performs privileged operations on a specific host (build/deploy execution, container operations, filesystem, volumes, logs, metrics).

This document defines a **from-scratch “v2” client API**. It does not assume compatibility with any existing routes.

---

## 1) Base URLs, Versioning, and Environments

### Server
- Base: `https://<chap-server-host>`
- API root: `/api/v2`

### Node
- Base: `https://<node-host>:<node-port>` (or an internal address reachable by client)
- API root: `/node/v2`

### Versioning policy
- URI versioning: `/api/v2` and `/node/v2`
- Backward-incompatible changes require a new major version.
- Deprecations are announced via response header `Deprecation` and `Sunset` (RFC 8594).

---

## 2) Conventions

### Content types
- Request: `Content-Type: application/json`
- Response: `application/json; charset=utf-8`

For uploads/downloads:
- File download uses `application/octet-stream`.
- Multi-file upload uses `multipart/form-data`.

### IDs
Resources expose:
- `id`: opaque string (UUID recommended)
- `created_at`, `updated_at`: RFC 3339 timestamps

### Pagination
All list endpoints support cursor pagination:
- `page[limit]` (default 50, max 200)
- `page[cursor]` (opaque)

Response:
```json
{
  "data": [],
  "page": { "next_cursor": "...", "limit": 50 }
}
```

### Filtering & sorting
- `filter[status]=running`
- `filter[team_id]=...`
- `sort=-created_at` (prefix `-` for descending)

### Idempotency
All **mutating** endpoints SHOULD support:
- `Idempotency-Key: <uuid>`

Server will return the same response for the same key within a 24h window.

### Errors
Errors are JSON with stable `code` values:
```json
{
  "error": {
    "code": "not_found",
    "message": "Application not found",
    "request_id": "req_...",
    "details": {"field": "..."}
  }
}
```

Common HTTP statuses:
- `400` invalid JSON / parameters
- `401` missing/invalid token
- `403` token lacks scope or resource access
- `404` not found
- `409` conflict (locks, deploy in progress)
- `422` validation error
- `429` rate limited
- `503` node offline / temporarily unavailable

---

## 3) Authentication & Authorization (Token Permissions)

### Token types
This is a **client API**, so tokens are obtained by an already-existing user session.

1) **Personal Access Token (PAT)** (Server)
- Long-lived bearer token created by the user in the UI.
- Supports scopes and optional resource constraints.
- Revocable.

2) **Session Token** (Server)
- Short-lived token obtained via login for CLI use.
- Optional if Chap chooses “PAT-only” for clients.

3) **Node Access Token** (Node)
- Short-lived JWT minted by the **Server** for a specific node.
- Has explicit scopes, resource constraints, TTL (e.g., 60–300 seconds).
- Used directly against the Node API.

4) **Deploy Token** (Server)
- Short-lived token limited to deployment trigger endpoints.
- Intended for CI systems.

### Token format
- `Authorization: Bearer <token>`
- Tokens SHOULD be JWTs, but opaque tokens are acceptable.

### Scope model
Scopes are strings. Tokens may include wildcard segments.

Examples:
- `projects:read`, `projects:write`
- `environments:read`, `environments:write`
- `applications:read`, `applications:write`, `applications:deploy`
- `deployments:read`, `deployments:cancel`, `deployments:rollback`
- `logs:read`, `logs:stream`
- `templates:read`
- `services:read`, `services:write`
- `databases:read`, `databases:write`
- `nodes:read`
- `nodes:session:mint` (ability to mint node access tokens)
- `files:read`, `files:write`, `files:delete`
- `exec:run` (interactive exec)
- `volumes:read`, `volumes:write`
- `activity:read`
- `webhooks:read`, `webhooks:write`
- `git_sources:read`, `git_sources:write`
- `settings:read`, `settings:write`

### Resource constraints (“scoped tokens”)
Tokens MAY include constraints limiting where scopes apply:
- `team_id`
- `project_id`
- `environment_id`
- `application_id`
- `node_id`

Authorization rule:
1) Token must include required scope.
2) Requested resource must be within token constraints (if present).
3) Server-side RBAC must still allow it (team role, project membership).

### Node token claims (recommended)
JWT claims (illustrative):
- `iss`: chap-server
- `aud`: chap-node
- `sub`: token id
- `exp`: expiry
- `node_id`: required
- `scopes`: list
- `constraints`: `{ "application_id": "...", "paths": ["/app", "/data"] }`

### Path-based permissions (filesystem)
Filesystem access is constrained by:
- Allowed roots (e.g., `/srv/chap/apps/<appId>`, volume mount points)
- Deny traversal (`..`), symlink escapes, device files
- Optional `paths` claim limiting reads/writes to specific subpaths

---

## 4) Rate Limits

Rate limits apply per token (and optionally per IP). Suggested defaults:

### Server API
- **Read endpoints**: 600 requests/minute
- **Write endpoints**: 120 requests/minute
- **Deploy triggers**: 30 requests/minute
- **Auth endpoints**: 10 requests/minute

### Node API
- **Read ops (logs/metrics/files listing)**: 600 requests/minute
- **Mutating ops (write files, restart containers, exec)**: 120 requests/minute
- **Heavy ops (build, image pull)**: 20 requests/minute

### Streaming limits
- Server: up to 5 concurrent SSE streams per token
- Node: up to 3 concurrent log streams per token

### Headers
Responses include (prefer RFC 9333 `RateLimit-*`):
- `RateLimit-Limit: 120;w=60`
- `RateLimit-Remaining: 42`
- `RateLimit-Reset: 17` (seconds)

On `429`:
- `Retry-After: <seconds>`

Client guidance:
- Use exponential backoff with jitter.
- Respect `Retry-After`.

---

## 5) Server API (Client-Facing) — `/api/v2`

### 5.1 Health & Metadata

#### GET `/api/v2/health`
Returns basic health and version.

Response:
```json
{ "status": "ok", "server_time": "2026-01-17T12:00:00Z", "version": "2.0.0" }
```

#### GET `/api/v2/capabilities`
Describes feature flags and limits configured on the server.

---

### 5.2 Auth (Client Tokens)

> User creation is intentionally out-of-scope.

#### POST `/api/v2/auth/session`
Exchanges credentials for a short-lived session token (optional design).

Request:
```json
{ "email": "user@example.com", "password": "...", "totp": "123456" }
```

Response:
```json
{ "access_token": "...", "token_type": "Bearer", "expires_in": 3600 }
```

#### POST `/api/v2/auth/tokens`
Creates a PAT (requires existing session and appropriate permission in UI policy).

Request:
```json
{
  "name": "my-cli",
  "expires_at": "2026-06-01T00:00:00Z",
  "scopes": ["projects:read","applications:deploy","logs:read"],
  "constraints": {"team_id": "team_..."}
}
```

Response includes the token once:
```json
{ "token_id": "pat_...", "token": "...", "created_at": "..." }
```

#### GET `/api/v2/auth/tokens`
Lists the caller’s tokens (never returns the secret again).

#### DELETE `/api/v2/auth/tokens/{token_id}`
Revokes a token.

---

### 5.3 Me / Current Context

#### GET `/api/v2/me`
Returns the caller identity plus current team context.

#### GET `/api/v2/teams`
Lists teams the caller can access.

#### POST `/api/v2/teams/{team_id}/select`
Sets active team for subsequent calls (or clients can pass `X-Team-Id`).

Header alternative:
- `X-Team-Id: team_...`

---

### 5.4 Projects

#### GET `/api/v2/projects`
Query supports `filter[team_id]`.

#### POST `/api/v2/projects`
Creates a project.

#### GET `/api/v2/projects/{project_id}`
#### PATCH `/api/v2/projects/{project_id}`
#### DELETE `/api/v2/projects/{project_id}`

#### GET `/api/v2/projects/{project_id}/members`
Lists project members & roles (read-only).

#### PATCH `/api/v2/projects/{project_id}/members/{user_id}`
Updates member role (requires `projects:members:write`). No user creation.

---

### 5.5 Environments

#### GET `/api/v2/projects/{project_id}/environments`
#### POST `/api/v2/projects/{project_id}/environments`
#### GET `/api/v2/environments/{environment_id}`
#### PATCH `/api/v2/environments/{environment_id}`
#### DELETE `/api/v2/environments/{environment_id}`

---

### 5.6 Applications

Applications are deployable workloads (Dockerfile, docker-compose, static, etc.).

#### GET `/api/v2/environments/{environment_id}/applications`
#### POST `/api/v2/environments/{environment_id}/applications`

Request (illustrative):
```json
{
  "name": "api",
  "node_id": "node_...",
  "source": {
    "provider": "github",
    "repo": "org/repo",
    "branch": "main"
  },
  "build": {
    "type": "dockerfile",
    "dockerfile_path": "Dockerfile",
    "context": ".",
    "build_args": {"NODE_ENV": "production"}
  },
  "runtime": {
    "ports": [{"container": 3000, "public": true}],
    "domains": ["api.example.com"],
    "env": {"KEY": "VALUE"},
    "resources": {"cpu": "1", "memory": "512m"},
    "healthcheck": {"enabled": true, "path": "/", "interval_sec": 30}
  }
}
```

#### GET `/api/v2/applications/{application_id}`
#### PATCH `/api/v2/applications/{application_id}`
#### DELETE `/api/v2/applications/{application_id}`

#### POST `/api/v2/applications/{application_id}:stop`
#### POST `/api/v2/applications/{application_id}:restart`

#### POST `/api/v2/applications/{application_id}:reserve-port`
Reserves a port on its assigned node.

#### POST `/api/v2/applications/{application_id}:release-port`

---

### 5.7 Deployments

#### POST `/api/v2/applications/{application_id}/deployments`
Triggers a deployment.

Request:
```json
{
  "reason": "manual",
  "git": {"ref": "main", "commit_sha": "optional"},
  "strategy": {"type": "rolling", "max_unavailable": 0}
}
```

Response:
```json
{ "deployment_id": "dep_...", "status": "queued" }
```

#### GET `/api/v2/applications/{application_id}/deployments`
#### GET `/api/v2/deployments/{deployment_id}`

#### POST `/api/v2/deployments/{deployment_id}:cancel`
#### POST `/api/v2/deployments/{deployment_id}:rollback`

#### GET `/api/v2/deployments/{deployment_id}/logs`
Returns a bounded log view (not streaming).

---

### 5.8 Logs & Events (Streaming)

#### GET `/api/v2/streams/events` (SSE)
Server-Sent Events for deployment/app/node changes.

Query:
- `filter[team_id]=...`
- `filter[application_id]=...`

Event types:
- `deployment.started`, `deployment.progress`, `deployment.finished`
- `node.online`, `node.offline`
- `container.health.changed`

#### GET `/api/v2/streams/logs` (SSE)
High-level log stream proxied by server (may internally connect to nodes).

> For heavy log streaming and file reads, prefer **Node API** using node tokens.

---

### 5.9 Nodes (Client View)

#### GET `/api/v2/nodes`
Lists nodes visible to the caller.

#### GET `/api/v2/nodes/{node_id}`
Includes status, capabilities, and resource availability.

#### POST `/api/v2/nodes/{node_id}/sessions`
Mints a **Node Access Token** and returns the node URL.

Request:
```json
{
  "scopes": ["logs:read","files:read"],
  "constraints": {
    "application_id": "app_...",
    "paths": ["/app", "/data"],
    "max_bytes": 10485760
  },
  "ttl_sec": 120
}
```

Response:
```json
{
  "node_url": "https://node-a.example.com:3000",
  "node_access_token": "...",
  "expires_in": 120
}
```

---

### 5.10 Databases

Databases are managed service instances (usually containers created from templates).

#### GET `/api/v2/environments/{environment_id}/databases`
#### POST `/api/v2/environments/{environment_id}/databases`
#### GET `/api/v2/databases/{database_id}`
#### PATCH `/api/v2/databases/{database_id}`
#### DELETE `/api/v2/databases/{database_id}`
#### POST `/api/v2/databases/{database_id}:start`
#### POST `/api/v2/databases/{database_id}:stop`

---

### 5.11 One-click Services

#### GET `/api/v2/environments/{environment_id}/services`
#### POST `/api/v2/environments/{environment_id}/services`
#### GET `/api/v2/services/{service_id}`
#### PATCH `/api/v2/services/{service_id}`
#### DELETE `/api/v2/services/{service_id}`
#### POST `/api/v2/services/{service_id}:start`
#### POST `/api/v2/services/{service_id}:stop`

---

### 5.12 Templates (Catalog)

#### GET `/api/v2/templates`
Supports filtering by category.

#### GET `/api/v2/templates/{template_slug}`
Includes schema for required inputs.

---

### 5.13 Git Sources (Integrations)

#### GET `/api/v2/git-sources`
#### POST `/api/v2/git-sources`
Creates a connection definition (e.g., GitHub App installation reference).

#### GET `/api/v2/git-sources/{git_source_id}`
#### DELETE `/api/v2/git-sources/{git_source_id}`

#### POST `/api/v2/git-sources/{git_source_id}:test`
Validates credentials/scopes.

#### GET `/api/v2/git-sources/{git_source_id}/repositories`
Lists repos visible to that integration.

---

### 5.14 Webhooks

#### POST `/api/v2/applications/{application_id}/webhooks`
Creates a signed webhook endpoint that triggers deployments.

Request:
```json
{ "events": ["push"], "secret_rotation": "auto" }
```

Response:
```json
{ "webhook_id": "wh_...", "url": "https://server/webhooks/...", "secret_hint": "..." }
```

#### POST `/api/v2/webhooks/{webhook_id}:rotate-secret`
#### DELETE `/api/v2/webhooks/{webhook_id}`

---

### 5.15 Settings & Activity

#### GET `/api/v2/settings`
Caller/team settings (UI preferences, defaults).

#### PATCH `/api/v2/settings`

#### GET `/api/v2/activity`
Audit trail (deploys, edits, token changes).

---

## 6) Node API (Privileged Host Operations) — `/node/v2`

Node endpoints require a **Node Access Token** minted by the server.

### 6.1 Node Health

#### GET `/node/v2/health`
Returns node status and agent version.

#### GET `/node/v2/capabilities`
Returns supported features: build engines, filesystem providers, metrics availability.

---

### 6.2 Applications on Node

#### GET `/node/v2/applications`
Lists applications the token is allowed to see (often constrained to one app).

#### GET `/node/v2/applications/{application_id}/status`
Includes containers, health, resource usage summary.

---

### 6.3 Deploy Execution

#### POST `/node/v2/deployments`
Starts an on-node deploy job (normally invoked by server, but clients may call if permitted).

Request:
```json
{
  "deployment_id": "dep_...",
  "application_id": "app_...",
  "source": {"type": "git", "repo": "...", "ref": "..."},
  "build": {"type": "dockerfile", "dockerfile_path": "Dockerfile", "context": "."},
  "limits": {"cpu": "1", "memory": "512m"}
}
```

Response:
```json
{ "job_id": "job_...", "status": "running" }
```

#### POST `/node/v2/deployments/{deployment_id}:cancel`
Attempts graceful cancel; returns `409` if too late.

---

### 6.4 Container Operations

#### GET `/node/v2/containers`
Optional filter: `filter[application_id]`.

#### GET `/node/v2/containers/{container_id}`

#### POST `/node/v2/containers/{container_id}:restart`
#### POST `/node/v2/containers/{container_id}:stop`

#### GET `/node/v2/containers/{container_id}/logs`
Query:
- `since` (RFC3339)
- `tail` (default 200)

#### GET `/node/v2/containers/{container_id}/logs/stream` (SSE)
Streaming logs.

---

### 6.5 Exec (Interactive)

> High-risk; guard with `exec:run` scope and short TTL.

#### POST `/node/v2/containers/{container_id}/exec`
Starts an exec session.

Request:
```json
{ "cmd": ["/bin/sh"], "tty": true, "env": {"TERM": "xterm-256color"} }
```

Response:
```json
{ "exec_id": "exec_...", "ws_url": "wss://node/.../exec/exec_..." }
```

#### WebSocket `GET wss://<node>/node/v2/exec/{exec_id}`
Bi-directional stream.

---

### 6.6 Filesystem (Browse / Read / Write)

Filesystem operations are limited by token `constraints.paths` and server-defined allowed roots.

#### GET `/node/v2/fs/ls`
Query:
- `path=/app`
- `recursive=false`
- `limit=2000`

#### GET `/node/v2/fs/stat`
Query: `path=/app/config.json`

#### GET `/node/v2/fs/read`
Query:
- `path=/app/config.json`
- `offset=0`
- `length=1048576`

Returns bytes (or JSON if `accept: application/json` with base64).

#### PUT `/node/v2/fs/write`
Writes bytes (supports atomic writes).

Request:
```json
{ "path": "/app/config.json", "mode": "0644", "atomic": true, "content_base64": "..." }
```

#### POST `/node/v2/fs/mkdir`
#### POST `/node/v2/fs/move`
#### POST `/node/v2/fs/copy`
#### DELETE `/node/v2/fs/delete`

#### POST `/node/v2/fs/chmod`
> Optional; restrict to safe modes.

#### POST `/node/v2/fs/upload` (multipart)
Uploads one or more files.

#### GET `/node/v2/fs/download`
Downloads a single file (optionally zipped for directories).

Security requirements:
- Normalize paths; reject traversal and symlink escapes.
- Enforce max file size per request via `constraints.max_bytes`.

---

### 6.7 Volumes

#### GET `/node/v2/volumes`
#### POST `/node/v2/volumes`
Creates a volume (if allowed).

#### POST `/node/v2/volumes/{volume_id}:attach`
#### POST `/node/v2/volumes/{volume_id}:detach`

#### POST `/node/v2/volumes/{volume_id}:snapshot`
Creates a snapshot artifact.

---

### 6.8 Metrics

#### GET `/node/v2/metrics/host`
CPU, memory, disk, load, network.

#### GET `/node/v2/metrics/containers`
Per-container CPU/mem IO.

#### GET `/node/v2/metrics/containers/{container_id}`

---

## 7) Cross-Plane Flows (How Clients Use Server + Node)

### 7.1 Read files for an application
1) Client calls Server: `POST /api/v2/nodes/{node_id}/sessions` with `files:read` and path constraints.
2) Server returns `node_url` + short-lived `node_access_token`.
3) Client calls Node: `GET /node/v2/fs/ls?path=/app` and `GET /node/v2/fs/read?...`.

### 7.2 Trigger deploy and follow logs
1) `POST /api/v2/applications/{application_id}/deployments`
2) Subscribe to `GET /api/v2/streams/events` filtered by `deployment_id`
3) If needed, mint node token and stream container logs via node.

---

## 8) Resource Limits (Enforced Everywhere)

All actions are subject to configured limits:
- Per-application CPU/memory quotas
- Node capacity and scheduling rules
- Disk quotas / max artifact sizes
- Port allocation constraints

When limits prevent an action:
- `422` for validation (e.g., invalid limit format)
- `409` for capacity conflicts (e.g., insufficient memory on node)
- `503` when node is offline/unreachable

---

## 9) Minimal OpenAPI Guidance (Optional)

This spec is written in Markdown for readability. When implementing, generate an OpenAPI 3.1 document with:
- Server and Node as separate `servers` entries
- Shared error schema
- Security schemes for `BearerAuth` and `NodeBearerAuth`

---

## 10) Quick Examples

### List projects
```bash
curl -sS https://your-chap-server/api/v2/projects \
  -H 'Authorization: Bearer <PAT>' \
  -H 'X-Team-Id: team_...'
```

### Mint node token and read a file
```bash
node_url=$(curl -sS -X POST https://your-chap-server/api/v2/nodes/node_123/sessions \
  -H 'Authorization: Bearer <PAT>' \
  -H 'Content-Type: application/json' \
  -d '{"scopes":["files:read"],"constraints":{"application_id":"app_456","paths":["/app"],"max_bytes":1048576},"ttl_sec":120}' \
| jq -r .node_url)

node_token=$(curl -sS -X POST https://your-chap-server/api/v2/nodes/node_123/sessions \
  -H 'Authorization: Bearer <PAT>' \
  -H 'Content-Type: application/json' \
  -d '{"scopes":["files:read"],"constraints":{"application_id":"app_456","paths":["/app"],"max_bytes":1048576},"ttl_sec":120}' \
| jq -r .node_access_token)

curl -sS "$node_url/node/v2/fs/read?path=/app/Dockerfile&offset=0&length=65536" \
  -H "Authorization: Bearer $node_token"
```

---

## 11) Suggested Next Implementation Steps

- Implement `POST /api/v2/nodes/{node_id}/sessions` first (it unlocks safe node access).
- Implement read-only lists (projects/environments/apps) with cursor pagination.
- Implement deployments as an asynchronous job model + SSE events.
- Add strict scope enforcement, path sandboxing, and idempotency keys.
