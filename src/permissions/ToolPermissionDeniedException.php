<?php

namespace markhuot\craftai\permissions;

class ToolPermissionDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $toolName,
        public readonly string $permission,
    ) {
        parent::__construct(sprintf(
            'You do not have permission to use the "%s" tool (missing permission: %s).',
            $toolName,
            $permission,
        ));
    }
}
