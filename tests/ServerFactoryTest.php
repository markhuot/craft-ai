<?php

use Mcp\Server;
use markhuot\craftai\mcp\ServerFactory;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolRegistry;

it('builds an mcp/sdk Server from the registered tools', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    $server = (new ServerFactory($registry))->build();

    expect($server)->toBeInstanceOf(Server::class);
});
