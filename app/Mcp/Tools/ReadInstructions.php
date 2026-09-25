<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('read_instructions')]
#[Description('Read your instruction layers: base content plus ordered sub-layers.')]
class ReadInstructions extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $instruction = $this->agent()->instruction;

        return Response::text(json_encode([
            'content' => $instruction?->content ?? '',
            'layers' => $instruction?->layersList() ?? [],
        ]));
    }
}
