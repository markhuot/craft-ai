<?php

it('redirects plugin settings to backends')
    ->actingAsAdmin()
    ->get('/admin/settings/plugins/ai')
    ->assertRedirect();
