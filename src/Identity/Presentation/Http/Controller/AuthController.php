<?php

declare(strict_types=1);

namespace Wob\Identity\Presentation\Http\Controller;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Wob\Identity\Application\Command\SignInWithGoogle;
use Wob\Identity\Application\Handler\SignInWithGoogleHandler;
use Wob\Identity\Domain\Model\User;

/**
 * Controllers here do three things and nothing else: read the request, call one
 * use case, shape the response. No branching on domain state, no repository
 * calls — the moment a controller starts deciding things, the same decision has
 * to be made again in every other entry point.
 */
final readonly class AuthController
{
    public function __construct(private SignInWithGoogleHandler $signIn)
    {
    }

    /**
     * Sign in with the credential Google Identity Services handed the browser.
     *
     * The session that comes back is a Sanctum cookie, not a token in the JSON
     * body. A token in the body has to be stored somewhere the page can read it,
     * which means any injected script can read it too; an http-only cookie
     * cannot be touched by JavaScript at all. The cost is that the SPA has to be
     * same-site with the API, which the Vite proxy in docs/frontend.md arranges.
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            "credential" => ["required", "string", "max:4096"],
        ]);

        $user = ($this->signIn)(new SignInWithGoogle($data["credential"]));

        // A fresh session id on sign-in: reusing the one the visitor arrived
        // with is what session fixation is.
        $request->session()->regenerate();
        $this->guard()->loginUsingId($user->id->value);

        return new JsonResponse(["user" => $this->present($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get("domainUser");

        return new JsonResponse(["user" => $user instanceof User ? $this->present($user) : null]);
    }

    public function signOut(Request $request): JsonResponse
    {
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonResponse(["user" => null]);
    }

    /**
     * Typed as StatefulGuard, not fetched inline: loginUsingId and logout live
     * on the stateful contract, and the generic Guard interface does not have
     * them. Reaching for them through auth() works at runtime and hides a real
     * assumption from static analysis.
     */
    private function guard(): StatefulGuard
    {
        $guard = auth()->guard("web");
        assert($guard instanceof StatefulGuard);

        return $guard;
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            "id" => $user->id->value,
            "email" => $user->email()->value,
            "name" => $user->displayName(),
            "avatar" => $user->avatarUrl(),
        ];
    }
}
