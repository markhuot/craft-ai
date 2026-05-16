<?php

namespace markhuot\craftai\agent;

use markhuot\craftai\tools\Tool;
use yii\base\Event;

/**
 * Event payload for {@see \markhuot\craftai\Plugin::EVENT_REGISTER_AGENT_TOOLS}.
 *
 * Listeners append entries to `$tools` and the plugin registers each one
 * after the event finishes firing. Mirrors Craft's own permission-
 * registration pattern (additive arrays on the event object) so listeners
 * can only *add* tools — they cannot remove or reorder existing ones.
 *
 * Example:
 *
 *     Event::on(
 *         Plugin::class,
 *         Plugin::EVENT_REGISTER_AGENT_TOOLS,
 *         function (RegisterAgentToolsEvent $event): void {
 *             $event->tools[] = ['class' => MyTool::class];
 *             $event->tools[] = ['class' => MyCpOnlyTool::class, 'cpOnly' => true];
 *         },
 *     );
 */
class RegisterAgentToolsEvent extends Event
{
    /**
     * @var list<array{class: class-string<Tool>, cpOnly?: bool}>
     */
    public array $tools = [];
}
