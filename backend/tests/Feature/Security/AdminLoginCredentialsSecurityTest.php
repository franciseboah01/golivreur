<?php

it('affiche les identifiants de test sur /admin/login uniquement lorsque APP_ENV est local', function () {
    app()->detectEnvironment(fn () => 'local');

    $response = $this->get('/admin/login');

    $response->assertOk()
        ->assertSee('Identifiants de test (Daloa)')
        ->assertSee('superadmin@golivreur.ci')
        ->assertSee('SuperAdmin2026!')
        ->assertSee('manager.daloa@golivreur.ci')
        ->assertSee('Manager2026!');
});

it('masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local', function (string $env) {
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
})->with(['staging', 'production', 'testing']);
