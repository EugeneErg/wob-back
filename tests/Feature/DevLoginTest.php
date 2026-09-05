<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Wob\Tests\TestCase;

/**
 * Вход без Google: работает локально и нигде больше.
 *
 * Проверяется прежде всего то, что он закрыт. Открытый в проде такой вход
 * означает вход в любой аккаунт по имени почты, поэтому замка два — окружение
 * и явный флаг, — и тест смотрит на каждый по отдельности.
 */
final class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    public function testItSignsInLocallyWhenTheFlagIsOn(): void
    {
        config(['auth.dev_login' => true]);
        app()->detectEnvironment(static fn (): string => 'local');

        $body = $this->postJson('/api/auth/dev', ['email' => 'author@wob.local'])
            ->assertOk()->json('user');

        self::assertSame('author@wob.local', $body['email']);

        // Вошли по-настоящему: следующий запрос уже знает, кто мы.
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'author@wob.local');
    }

    public function testItIsClosedWithoutTheFlag(): void
    {
        config(['auth.dev_login' => false]);
        app()->detectEnvironment(static fn (): string => 'local');

        $this->postJson('/api/auth/dev')->assertStatus(404);
    }

    public function testItIsClosedOutsideLocalEvenWithTheFlag(): void
    {
        config(['auth.dev_login' => true]);
        app()->detectEnvironment(static fn (): string => 'production');

        $this->postJson('/api/auth/dev')->assertStatus(404);
    }
}
