<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Where the server is
    |---------------------------------------------------------------------------
    |
    | The default is what `docker run openfga/openfga run` listens on.
    |
    */

    'url' => env('OPENFGA_URL', 'http://localhost:8080'),

    /*
    |---------------------------------------------------------------------------
    | Store
    |---------------------------------------------------------------------------
    |
    | The store id - a ULID, not the store's name. Create a store once with
    | `php artisan openfga:store` or OpenFGA's CLI and put its id here.
    |
    */

    'store' => env('OPENFGA_STORE_ID'),

    /*
    |---------------------------------------------------------------------------
    | Authorization model
    |---------------------------------------------------------------------------
    |
    | Pin this. Leaving it empty means every check is answered by whichever
    | model version is newest, so deploying a model change silently changes the
    | answers this application gets, with no deployment of its own.
    |
    | Models are immutable and append-only, so an id you shipped keeps working
    | for ever. Set it at deploy time and change it on purpose.
    |
    */

    'model' => env('OPENFGA_MODEL_ID'),

    /*
    |---------------------------------------------------------------------------
    | Authentication
    |---------------------------------------------------------------------------
    |
    | "none" for a server started with no flags, which is the development
    | default. "preshared" for one started with --authn-method=preshared.
    | "client_credentials" for a managed deployment.
    |
    */

    'credentials' => [
        'method' => env('OPENFGA_AUTH_METHOD', 'none'),

        'token' => env('OPENFGA_API_TOKEN'),

        'client_credentials' => [
            'issuer' => env('OPENFGA_TOKEN_ISSUER'),
            'client_id' => env('OPENFGA_CLIENT_ID'),
            'client_secret' => env('OPENFGA_CLIENT_SECRET'),
            'audience' => env('OPENFGA_API_AUDIENCE'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | How a Laravel user is named to OpenFGA
    |---------------------------------------------------------------------------
    |
    | The authenticated user becomes "<user_type>:<auth identifier>". Implement
    | Piston\OpenFga\Contracts\FgaUser on your user model to
    | decide this per user instead.
    |
    */

    'user_type' => env('OPENFGA_USER_TYPE', 'user'),

    /*
    |---------------------------------------------------------------------------
    | Gate integration
    |---------------------------------------------------------------------------
    |
    | Registers a Gate::before callback so that Gate::allows('viewer', $model)
    | asks OpenFGA - but only for models implementing FgaObject. Everything
    | else falls through to your existing policies untouched.
    |
    | Turn it off to keep the Gate entirely yours and call the client directly.
    |
    */

    'gate' => env('OPENFGA_GATE', true),

    /*
    |---------------------------------------------------------------------------
    | Tuples per write
    |---------------------------------------------------------------------------
    |
    | The server's --max-tuples-per-write. A write over this is refused rather
    | than split, because writes are transactional and splitting one would turn
    | an atomic change into several that can half-fail.
    |
    */

    'max_tuples_per_write' => env('OPENFGA_MAX_TUPLES_PER_WRITE', 100),

];
