<?php

it('masque totalement login-credentials sur /admin/login et au rendu direct dans tous les environnements', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    $response = $this->get('/admin/login');

    $response->assertOk()
        ->assertDontSee('Identifiants de test')
        ->assertDontSee('superadmin@golivreur.ci')
        ->assertDontSee('SuperAdmin2026!')
        ->assertDontSee('manager.daloa@golivreur.ci')
        ->assertDontSee('Manager2026!')
        ->assertDontSee('fillAdminCredentials');

    $renderedView = view('filament.admin.login-credentials')->render();

    expect($renderedView)
        ->not->toContain('Identifiants de test')
        ->not->toContain('superadmin@golivreur.ci')
        ->not->toContain('SuperAdmin2026!')
        ->not->toContain('manager.daloa@golivreur.ci')
        ->not->toContain('Manager2026!');
})->with(['local', 'staging', 'production', 'testing']);
