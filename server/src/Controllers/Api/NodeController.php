<?php

namespace Chap\Controllers\Api;

use Chap\Models\Node;

/**
 * API Node Controller
 */
class NodeController extends BaseApiController
{
    /**
     * List nodes
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        $nodes = Node::all();

        $this->success([
            'nodes' => array_map(fn($n) => $n->toArray(), $nodes),
        ]);
    }

    /**
     * Create node
     */
    public function store(): void
    {
        $this->currentTeam();
        $data = $this->all();

        if (empty($data['name'])) {
            $this->validationError(['name' => 'Name is required']);
            return;
        }

        $node = Node::create([
            'uuid' => uuid(),
            'team_id' => null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'token' => generate_token(32),
            'status' => 'pending',
        ]);

        $this->success(['node' => $node->toArray()], 201);
    }

    /**
     * Show node
     */
    public function show(string $id): void
    {
        $this->currentTeam();
        $node = Node::findByUuid($id);

        if (!$node) {
            $this->notFound('Node not found');
            return;
        }

        $this->success(['node' => $node->toArray()]);
    }

    /**
     * Update node
     */
    public function update(string $id): void
    {
        $this->currentTeam();
        $node = Node::findByUuid($id);

        if (!$node) {
            $this->notFound('Node not found');
            return;
        }

        $data = $this->all();

        $node->update([
            'name' => $data['name'] ?? $node->name,
            'description' => $data['description'] ?? $node->description,
        ]);

        $this->success(['node' => $node->toArray()]);
    }

    /**
     * Delete node
     */
    public function destroy(string $id): void
    {
        $this->currentTeam();
        $node = Node::findByUuid($id);

        if (!$node) {
            $this->notFound('Node not found');
            return;
        }

        $node->delete();

        $this->success(['message' => 'Node deleted']);
    }
}
