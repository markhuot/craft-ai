<?php

use markhuot\craftai\agent\RegisterAgentToolsEvent;
use markhuot\craftai\fields\UpdateCodeComponent;
use markhuot\craftai\Plugin;

it('exposes EVENT_REGISTER_AGENT_TOOLS as a class constant', function () {
    expect(Plugin::EVENT_REGISTER_AGENT_TOOLS)->toBe('registerAgentTools');
});

it('registers update_code_component via the event during plugin init', function () {
    $plugin = Plugin::getInstance();
    $registry = $plugin->getToolRegistry();

    $descriptor = $registry->describe('update_code_component');

    expect($descriptor)->not->toBeNull();
    expect($descriptor->toolClass)->toBe(UpdateCodeComponent::class);
});

it('hides update_code_component from MCP descriptors because it is cpOnly', function () {
    $plugin = Plugin::getInstance();
    $registry = $plugin->getToolRegistry();

    $publicNames = array_map(
        static fn ($d): string => $d->name,
        $registry->descriptors(includeCpOnly: false),
    );

    expect($publicNames)->not->toContain('update_code_component');
});

it('lets a listener contribute additional tools through the event payload', function () {
    $event = new RegisterAgentToolsEvent();
    $event->tools[] = ['class' => UpdateCodeComponent::class];
    $event->tools[] = ['class' => UpdateCodeComponent::class, 'cpOnly' => true];

    expect($event->tools)->toHaveCount(2);
    expect($event->tools[0]['class'])->toBe(UpdateCodeComponent::class);
    expect($event->tools[1]['cpOnly'])->toBeTrue();
});
