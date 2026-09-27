<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentApiKey;
use App\Models\SharedFile;
use App\Models\SharedSpace;
use App\Models\VfsFile;
use App\Support\FileMime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Raw-bytes companion to the JSON tool surface: `PUT /api/files/{path}`
 * stores the request body verbatim (no base64 tax), `GET` hands it back
 * with its sniffed mime. `/api/shared/{space}/files/{path}` does the same
 * inside a shared space the agent holds a grant on.
 *
 * Auth is identical to /api/tools — ResolveApiAgent sets `agent` and the
 * optional scoped `api_key`; scope checks mirror ToolCatalog.
 */
class FileController extends Controller
{
    public function show(Request $request, string $path): HttpResponse
    {
        $agent = $this->authorizedAgent($request, 'files:read');

        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        try {
            $file = VfsFile::fileFor($agent, $path);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if ($file === null) {
            return $this->fail('File not found.', 404);
        }

        return $this->raw($file);
    }

    public function update(Request $request, string $path): JsonResponse
    {
        $agent = $this->authorizedAgent($request, 'files:write');

        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $bytes = $request->getContent();

        if (! is_string($bytes) || $bytes === '') {
            return $this->fail('Request body must be the file bytes.', 422);
        }

        return $this->store($agent, null, $path, $bytes, $request->header('Content-Type'));
    }

    public function showShared(Request $request, string $space, string $path): HttpResponse
    {
        $agent = $this->authorizedAgent($request, 'shared:read');

        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $space = $this->space($space);

        // Unknown space and missing grant are deliberately indistinguishable.
        if ($space === null || ! $space->canRead($agent)) {
            return $this->fail('Shared space not found.', 404);
        }

        try {
            $file = SharedFile::fileFor($space, $path);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if ($file === null) {
            return $this->fail('File not found.', 404);
        }

        return $this->raw($file);
    }

    public function updateShared(Request $request, string $space, string $path): JsonResponse
    {
        $agent = $this->authorizedAgent($request, 'shared:write');

        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $space = $this->space($space);

        if ($space === null || ! $space->canWrite($agent)) {
            return $this->fail('Shared space not found.', 404);
        }

        $bytes = $request->getContent();

        if (! is_string($bytes) || $bytes === '') {
            return $this->fail('Request body must be the file bytes.', 422);
        }

        return $this->store($agent, $space, $path, $bytes, $request->header('Content-Type'));
    }

    /**
     * Shared write path for both trees: UTF-8 bodies with a text-ish mime
     * stay text (console-editable), everything else lands as a blob.
     *
     * @param  SharedSpace|null  $space  null = the agent's private tree
     */
    private function store(Agent $agent, ?SharedSpace $space, string $path, string $bytes, ?string $declaredMime): JsonResponse
    {
        $mime = FileMime::normalize($declaredMime);

        try {
            $file = FileMime::isTextual($bytes, $mime)
                ? ($space === null
                    ? VfsFile::writeFor($agent, $path, $bytes)
                    : SharedFile::writeFor($space, $path, $bytes))
                : ($space === null
                    ? VfsFile::writeBinaryFor($agent, $path, $bytes, $mime)
                    : SharedFile::writeBinaryFor($space, $path, $bytes, $mime));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return response()->json([
            'ok' => true,
            'path' => $file->path,
            'bytes' => $file->byteSize(),
            'mime' => $file->mime,
        ]);
    }

    /**
     * The resolved agent, or a ready-to-return error response. Mirrors
     * ToolCallController: persona-less fleet keys 401, scoped keys 403
     * without the required scope.
     */
    private function authorizedAgent(Request $request, string $scope): Agent|JsonResponse
    {
        $agent = $request->attributes->get('agent');

        if (! $agent instanceof Agent) {
            return $this->fail('Agent persona required.', 401);
        }

        $apiKey = $request->attributes->get('api_key');

        if ($apiKey instanceof AgentApiKey && ! $apiKey->allows($scope)) {
            return $this->fail("This key lacks the {$scope} scope.", 403);
        }

        return $agent;
    }

    private function space(string $name): ?SharedSpace
    {
        return SharedSpace::query()->where('name', $name)->first();
    }

    private function raw(VfsFile|SharedFile $file): HttpResponse
    {
        return response($file->bytes(), 200, [
            'Content-Type' => $file->mime ?? 'text/plain; charset=utf-8',
            'Content-Length' => (string) $file->byteSize(),
        ]);
    }

    private function fail(string $error, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error], $status);
    }
}
