<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ListShared;
use App\Mcp\Tools\ReadFile;
use App\Mcp\Tools\ReadInstructions;
use App\Mcp\Tools\ReadShared;
use App\Mcp\Tools\WriteFile;
use App\Mcp\Tools\WriteShared;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('melfina')]
#[Version('0.0.1')]
#[Instructions('You are connected to your own private Melfina brain. All tools are scoped to the agent whose key authenticated this request — you can only see and touch your own files and instructions.')]
class MelfinaServer extends Server
{
    protected array $tools = [
        ListFiles::class,
        ReadFile::class,
        WriteFile::class,
        ReadInstructions::class,
        ListShared::class,
        ReadShared::class,
        WriteShared::class,
    ];
}
