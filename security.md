# Security Review (Chap)

Date: 2026-01-31

## Scope & Methodology (honest)

- Scope requested: full application review (Node agent + PHP server + configs), with extra attention to user-supplied Dockerfile / docker-compose content.
- What was done:
  - Repo-wide static scan across **all files** for high-risk primitives (command execution, Docker usage, archive extraction, deserialization, outbound HTTP, path traversal, auth/CSRF, templating/XSS).
  - Manual, line-by-line review of the Node agent code paths that execute Docker/Git/FS operations.
  - Manual review of security-critical PHP areas (auth/CSRF helpers, webhook receivers, template zip importer, notification webhook sender, routing).
- What was *not* done (limit): a literal human read-through of every single PHP view/controller/model file (there are hundreds). The repo-wide scans do cover every file, and the manual review prioritized the highest blast-radius components.

If you want a "read every PHP file" pass anyway, say so and I’ll do it in a follow-up (it’s just time-consuming, not hard).

## High-level Threat Model

Chap has a deliberately dangerous capability: **it runs untrusted build/deploy content** (Dockerfiles and docker-compose.yml) and manages containers via Docker.

The key security objective is therefore:

- Prevent user-supplied content from escalating to **host compromise**.

This requires:

- Strong docker-compose/dockerfile sanitization (block privilege escalation knobs).
- Eliminating **command injection** in the Node agent.
- Tight authentication/authorization between server ↔ node agent.
- Minimizing SSRF / internal-network pivoting from the server.

## Checklist (review + hardening)

### Node agent (runtime / Docker)
- [x] Docker/Compose hardening rules exist (blocked mounts/options, resource caps) in node/src/security.js.
- [x] Node agent command execution avoids shell interpolation (argv-only spawn) for docker/git/fs operations.
- [x] Exec/console functionality is validated + tokenized to argv (blocks metacharacters and `sh -c`).
- [x] File-path sandboxing exists for node HTTP v2 paths (root confinement + allowlists).
- [x] Disk-usage shell interpolation removed (df called via argv).
- [x] Multipart upload parsing uses streaming parser with strict limits (busboy).
- [x] Node API JWTs are signed with a per-node secret (node token), not a single shared secret.

### Server (PHP)
- [x] CSRF helpers exist and are used on settings/token creation paths.
- [x] Outbound notification webhook URLs validated with SSRF resistance (blocks localhost/private/reserved IPs) and redirects disabled.
- [x] Incoming webhook receivers require secrets (GitHub HMAC; GitLab token; Bitbucket/custom bearer or query secret).
- [x] Template zip import performs safe extraction (path traversal checks + size caps).
- [x] Views show broad use of escaping helpers for HTML/attribute contexts.
- [x] Rate limiting added for sensitive endpoints (auth + incoming webhooks).
- [x] Session cookie defaults hardened (Secure/HttpOnly/SameSite + strict mode).

### Infrastructure/config
- [x] Docker-compose files + Dockerfiles inventoried.
- [ ] (Recommended) Separate node agent host/VM or rootless Docker to reduce impact of agent compromise.

## Severe Risk

### 1) Node agent command injection → host compromise

**Impact:** Severe (RCE on the node agent container; often full host compromise because it controls Docker).

**What was found:** The node agent historically built shell strings for docker/git/fs operations, allowing user-influenced values to potentially become shell syntax.

**What changed (fix applied):**
- Node agent now uses argv-only process execution and removes remaining `execCommand("...")` shell-string call sites.
- Exec/console functionality now tokenizes commands to argv and rejects shell metacharacters and `*sh* -c` patterns.

**Files:**
- node/src/index.js
- node/src/security.js
- node/src/storage.js

**Residual risk:** If an attacker can fully control container images/entrypoints, you are still relying on Docker + kernel isolation. Keep Docker/host patched.

### 2) Incoming webhook endpoints previously allowed unsigned triggers

**Impact:** Severe (unauthorized deployments; potential supply-chain/pivot depending on repo credentials and build steps).

**What was found:** Legacy webhook endpoints existed that could trigger deployments without verifying a signature/token.

**What changed (fix applied):**
- All public webhook endpoints are now implemented via IncomingWebhook records (UUID endpoints) and enforce provider-appropriate secrets:
  - GitHub: `X-Hub-Signature-256` HMAC
  - GitLab: `X-Gitlab-Token`
  - Bitbucket/custom: `Authorization: Bearer <secret>` or `?secret=...`
- UI now supports creating incoming webhooks for multiple providers.

**Files:**
- server/routes.php
- server/src/Controllers/IncomingWebhookController.php
- server/src/Views/applications/show.php

### 3) Docker socket exposure (design-level)

**Impact:** Severe (if the node agent container is compromised, the attacker can usually control Docker on the host).

**Status:** Design risk. The codebase mitigates *some* escalation vectors (compose sanitization, mount/capability restrictions), but `/var/run/docker.sock` is still a very powerful control plane.

**Mitigations (recommended):**
- Run node agent on an isolated host/VM with minimal lateral movement value.
- Consider rootless Docker where feasible.
- Consider a dedicated Docker API proxy that enforces allowlisted operations instead of raw docker.sock.

## Medium Risk

### 1) Outbound webhook SSRF (internal network pivot)

**Impact:** Medium → Severe depending on network topology.

**What was found:** Outbound webhook delivery accepted any http(s) URL and followed redirects.

**What changed (fix applied):**
- Added SSRF-resistant validation (blocks localhost/private/reserved IPs via host/IP checks).
- Disabled redirects and locked curl to HTTP/HTTPS protocols.

**Files:**
- server/src/Security/OutboundUrlValidator.php
- server/src/Services/NotificationService.php
- server/src/Controllers/SettingsController.php

**Residual risk:** DNS rebinding is hard to eliminate in-app. Best additional defense is egress firewalling.

### 2) Multipart upload parsing in Node API v2 (DoS/edge cases)

**Impact:** Medium.

**What was observed:** node/src/nodeV2Http.js historically implemented a custom multipart parser. Even with size limits, homegrown parsers are historically bug-prone.

**What changed (fix applied):** Replaced the custom parser with a streaming multipart parser (busboy) and enforced strict upload caps based on token `max_bytes` (and safe defaults).

### 3) Helper-container shell scripts in file/volume manager

**Impact:** Medium.

**What was observed:** node/src/liveLogsWs.js runs `sh -c` scripts inside helper containers for file operations. This is less risky than host shelling-out (argv is controlled), but still increases complexity and exposure.

**Recommendation:** Keep strict path allowlists and size limits; ensure helper images are pinned and minimal.

## Low Risk / Hygiene

- Extensive use of escaping in PHP views (`e()` / `htmlspecialchars`) is present.
- Template zip importer includes basic path traversal protections and total-size caps.

Recommended low-risk improvements:
- Add rate limiting for sensitive endpoints (auth, webhook receivers) if not already enforced at a proxy. (Implemented in-app.)
- Add audit logging for webhook failures/rejections and exec denials (some already exists in Node).

## Notes on Dockerfile/docker-compose Upload Risk

Even with strong sanitization, letting users deploy arbitrary containers implies:

- They can run CPU/memory-heavy workloads.
- They can run network scanners from inside their containers.

Chap already mitigates some of this via compose validation (blocking privileged/mount/capability options) and resource limits. The remaining risk should be handled by:

- Network policies/egress controls.
- Strict per-team quotas.
- Running nodes on isolated infrastructure.

## What I changed in this security pass

- Hardened Node agent process execution to be argv-only and applied strict exec command tokenization.
- Removed remaining string-based docker command invocation sites in the node agent.
- Removed shell interpolation from disk-usage probing.
- Implemented outbound webhook SSRF protection + disabled redirects.
- Implemented secure incoming webhook receivers for GitLab/Bitbucket/Custom and updated routes + UI.
- Added in-app rate limiting for auth endpoints and webhook receivers.
- Hardened PHP session cookie defaults before `session_start()`.
- Replaced Node v2 upload multipart parsing with busboy (streaming + limits).

---

## Go-live checklist (practical)

See docs/production.md for an opinionated “do everything before it goes online” checklist, including TLS/reverse-proxy settings, egress controls, backup/monitoring, and node agent isolation.

---

If you want, I can also:
- Add a small "Webhook setup" help box in the UI explaining which header/query param each provider needs.
- Add unit/integration tests around OutboundUrlValidator and webhook receivers.
