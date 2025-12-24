<?php

namespace Chap\Controllers;

use Chap\Models\Node;
use Chap\Models\ActivityLog;

/**
 * Node Controller
 */
class NodeController extends BaseController
{
    /**
     * List nodes
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        $nodes = Node::forTeam($team->id);

        if ($this->isApiRequest()) {
            $this->json([
                'nodes' => array_map(fn($n) => $n->toArray(), $nodes)
            ]);
        } else {
            $this->view('nodes/index', [
                'title' => 'Nodes',
                'nodes' => $nodes,
            ]);
        }
    }

    /**
     * Show create node form
     */
    public function create(): void
    {
        $this->view('nodes/create', [
            'title' => 'Add Node'
        ]);
    }

    /**
     * Store new node
     */
    public function store(): void
    {
        $team = $this->currentTeam();

        if ($this->isApiRequest()) {
            $data = $this->all();
        } else {
            if (!verify_csrf($this->input('_csrf_token', ''))) {
                flash('error', 'Invalid request');
                $this->redirect('/nodes/create');
            }
            $data = $this->all();
        }

        // Validate
        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        } else {
            // Must be lowercase, no spaces, only a-z, 0-9, and dashes (multiple dashes allowed)
            if (!preg_match('/^[a-z0-9-]+$/', $data['name'])) {
                $errors['name'] = 'Node name must be lowercase, no spaces, only letters, numbers, and dashes (e.g. production-server-1)';
            }
        }

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => $errors], 422);
            } else {
                $_SESSION['_errors'] = $errors;
                $_SESSION['_old_input'] = $data;
                $this->redirect('/nodes/create');
            }
        }

        // Create node
        $token = generate_token(32);
        
        $node = Node::create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'logs_websocket_url' => $data['logs_websocket_url'] ?? null,
            'token' => $token,
            'status' => 'pending',
        ]);

        ActivityLog::log('node.created', 'Node', $node->id, ['name' => $node->name]);

        if ($this->isApiRequest()) {
            $this->json([
                'node' => $node->toArray(),
                'token' => $token, // Only returned once
            ], 201);
        } else {
            flash('success', 'Node created successfully');
            $_SESSION['node_token'] = $token; // Show token once
            $this->redirect('/nodes/' . $node->uuid);
        }
    }

    /**
     * Show node details
     */
    public function show(string $uuid): void
    {
        $team = $this->currentTeam();
        $node = Node::findByUuid($uuid);

        if (!$node || $node->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Node not found'], 404);
            } else {
                flash('error', 'Node not found');
                $this->redirect('/nodes');
            }
        }

        // Get one-time token display
        $showToken = $_SESSION['node_token'] ?? null;
        unset($_SESSION['node_token']);

        if ($this->isApiRequest()) {
            $this->json(['node' => $node->toArray()]);
        } else {
            $this->view('nodes/show', [
                'title' => $node->name,
                'node' => $node,
                'showToken' => $showToken,
                'containers' => $node->containers(),
            ]);
        }
    }

    /**
     * Update node
     */
    public function update(string $uuid): void
    {
        $team = $this->currentTeam();
        $node = Node::findByUuid($uuid);

        if (!$node || $node->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Node not found'], 404);
            } else {
                flash('error', 'Node not found');
                $this->redirect('/nodes');
            }
        }

        $data = $this->all();

        $node->update([
            'name' => $data['name'] ?? $node->name,
            'description' => $data['description'] ?? $node->description,
            'logs_websocket_url' => $data['logs_websocket_url'] ?? $node->logs_websocket_url,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['node' => $node->toArray()]);
        } else {
            flash('success', 'Node updated');
            $this->redirect('/nodes/' . $uuid);
        }
    }

    /**
     * Delete node
     */
    public function destroy(string $uuid): void
    {
        $team = $this->currentTeam();
        $node = Node::findByUuid($uuid);

        if (!$node || $node->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Node not found'], 404);
            } else {
                flash('error', 'Node not found');
                $this->redirect('/nodes');
            }
        }

        $nodeName = $node->name;
        $node->delete();

        ActivityLog::log('node.deleted', 'Node', null, ['name' => $nodeName]);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Node deleted']);
        } else {
            flash('success', 'Node deleted');
            $this->redirect('/nodes');
        }
    }

    /**
     * Regenerate node token
     */
    public function regenerateToken(string $uuid): void
    {
        $team = $this->currentTeam();
        $node = Node::findByUuid($uuid);

        if (!$node || $node->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Node not found'], 404);
            } else {
                flash('error', 'Node not found');
                $this->redirect('/nodes');
            }
        }

        $token = $node->regenerateToken();

        if ($this->isApiRequest()) {
            $this->json(['token' => $token]);
        } else {
            flash('success', 'Token regenerated');
            $_SESSION['node_token'] = $token;
            $this->redirect('/nodes/' . $uuid);
        }
    }
}
