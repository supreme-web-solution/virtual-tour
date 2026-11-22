<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Api\ApiPresenter;
use App\Services\Api\WorkspaceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A read-only Model Context Protocol server (JSON-RPC 2.0 over HTTP POST), so
 * an AI assistant can look up a workspace's properties, tours, leads and
 * media with the same token as the REST API.
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
 */
class McpController extends Controller
{
    public function __construct(protected WorkspaceData $data, protected ApiPresenter $presenter) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $message = json_decode($request->getContent(), true);

        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error(null, -32600, 'Invalid request.');
        }

        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // Notifications carry no id and get no body back.
        if (! array_key_exists('id', $message)) {
            return response()->noContent(202);
        }

        return match ($message['method']) {
            'initialize' => $this->result($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => config('app.name').' MCP', 'version' => '1.0.0'],
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($request, $id, $params),
            default => $this->error($id, -32601, 'Method not found.'),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function call(Request $request, mixed $id, array $params): JsonResponse
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! in_array($name, array_column($this->tools(), 'name'), true)) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }

        /** @var Workspace $workspace */
        $workspace = $request->user()->currentWorkspace;
        $workspaceId = $workspace->id;
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));

        $output = match ($name) {
            'get_workspace' => $this->data->workspace($workspace),
            'list_properties' => $this->data->properties($workspaceId, $this->text($arguments, 'search'))->limit($limit)->get()->map($this->presenter->property(...))->all(),
            'get_property' => ($found = $this->data->property($workspaceId, (string) ($arguments['id'] ?? ''))) === null ? null : $this->presenter->property($found),
            'list_tours' => $this->data->tours($workspaceId)->limit($limit)->get()->map(fn ($tour) => $this->presenter->tour($tour))->all(),
            'get_tour' => ($found = $this->data->tour($workspaceId, (string) ($arguments['id'] ?? ''))) === null ? null : $this->presenter->tour($found, withScenes: true),
            'list_leads' => $this->data->leads($workspaceId)->limit($limit)->get()->map($this->presenter->lead(...))->all(),
            default => $this->data->media($workspaceId, $this->text($arguments, 'type'))->limit($limit)->get()->map($this->presenter->media(...))->all(),
        };

        if ($output === null) {
            return $this->result($id, ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Not found.']]]);
        }

        return $this->result($id, ['content' => [['type' => 'text', 'text' => json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function text(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    protected function tools(): array
    {
        $limit = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many to return (default 20).']];
        $id = ['id' => ['type' => 'string', 'description' => 'The id from a list result.']];

        return [
            ['name' => 'get_workspace', 'description' => 'The workspace name and its available AI credits.', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'list_properties', 'description' => 'Properties in the workspace, newest first.', 'inputSchema' => ['type' => 'object', 'properties' => [...$limit, 'search' => ['type' => 'string', 'description' => 'Match name, address or city.']]]],
            ['name' => 'get_property', 'description' => 'One property by id.', 'inputSchema' => ['type' => 'object', 'properties' => $id, 'required' => ['id']]],
            ['name' => 'list_tours', 'description' => 'Virtual tours, newest first, with their public links when published.', 'inputSchema' => ['type' => 'object', 'properties' => $limit]],
            ['name' => 'get_tour', 'description' => 'One virtual tour by id, including its scenes.', 'inputSchema' => ['type' => 'object', 'properties' => $id, 'required' => ['id']]],
            ['name' => 'list_leads', 'description' => 'Enquiries sent from tour contact forms, newest first.', 'inputSchema' => ['type' => 'object', 'properties' => $limit]],
            ['name' => 'list_media', 'description' => 'Library photos, panoramas, videos and other files.', 'inputSchema' => ['type' => 'object', 'properties' => [...$limit, 'type' => ['type' => 'string', 'enum' => ['photo', 'panorama', 'video', 'audio', 'logo', 'floor_plan']]]]],
        ];
    }

    protected function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    protected function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
