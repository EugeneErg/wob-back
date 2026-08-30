<?php

declare(strict_types=1);

namespace Wob\Identity\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wob\Identity\Domain\Model\UserId;
use Wob\Identity\Domain\Repository\UserRepository;

/**
 * Turns the framework notion of "authenticated" into the domain notion of a
 * User, once per request.
 *
 * The alternative — making the domain User an Eloquent model so that Laravel
 * auth can return it directly — is exactly the shortcut that ends with the
 * domain depending on the framework. One lookup is a small price for the domain
 * not knowing what a guard is.
 */
final readonly class ResolveDomainUser
{
    public function __construct(private UserRepository $users)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $id = auth()->guard("web")->id();

        if ($id === null) {
            return new JsonResponse(["error" => ["code" => "unauthenticated", "message" => "Sign in first"]], 401);
        }

        $user = $this->users->find(new UserId((string) $id));

        if ($user === null) {
            // Signed in as somebody who no longer exists: the account was
            // deleted while the cookie lived on.
            $guard = auth()->guard("web");
            assert($guard instanceof StatefulGuard);
            $guard->logout();

            return new JsonResponse(["error" => ["code" => "unauthenticated", "message" => "Sign in first"]], 401);
        }

        $request->attributes->set("domainUser", $user);
        $request->attributes->set("ownerId", $user->id->value);

        return $next($request);
    }
}
