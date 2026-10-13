<?php

declare(strict_types=1);

namespace Piston\OpenFga\Bridge\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Piston\OpenFga\Bridge\Laravel\OpenFgaAuthorizer;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `fga:viewer,document`.
 *
 *     Route::get('/documents/{document}', ShowDocument::class)
 *         ->middleware('fga:viewer,document');
 *
 * The first argument is the relation, the second the name of the route
 * parameter holding the object. The parameter may be a bound model that
 * implements FgaObject, or a bare id - in which case the type is taken from
 * the parameter name, so `{document}` means `document:<id>`.
 *
 * The third, optional, argument overrides that type for the case where the
 * route reads `{doc}` and the model is called `document`:
 *
 *     ->middleware('fga:viewer,doc,document')
 *
 * It aborts with 403 on a denial and 401 when nobody is authenticated. It
 * refuses to start at all if the route parameter is missing, because a
 * middleware that silently allowed a request it could not evaluate would be
 * the worst possible bug in this package.
 */
final class AuthorizeWithOpenFga
{
    public function __construct(private readonly OpenFgaAuthorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $relation, string $parameter, ?string $type = null): Response
    {
        $user = $this->authorizer->userFor($request->user());

        if ($user === null) {
            abort(401);
        }

        $route = $request->route();

        if ($route === null || ! method_exists($route, 'parameter')) {
            throw new RuntimeException(
                'The fga middleware needs a route to read its parameter from; it cannot be used on a '
                .'request that was not routed.',
            );
        }

        if (! $route->hasParameter($parameter)) {
            throw new RuntimeException(sprintf(
                'The fga middleware was asked for the route parameter "%s", which this route does not '
                .'declare. Check the middleware arguments against the route\'s URI.',
                $parameter,
            ));
        }

        $value = $route->parameter($parameter);
        $object = $this->authorizer->objectFor($value);

        if ($object === null) {
            $id = is_scalar($value) ? (string) $value : null;

            if ($id === null || $id === '') {
                throw new RuntimeException(sprintf(
                    'The route parameter "%s" is neither an FgaObject nor something that can be named to '
                    .'OpenFGA, so authorisation cannot be decided.',
                    $parameter,
                ));
            }

            $object = ($type ?? $parameter).':'.$id;
        }

        abort_unless($this->authorizer->__invoke($user, $relation, [$object]) === true, 403);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
