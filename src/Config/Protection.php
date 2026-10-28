<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

/**
 * What the gateway does with a request for a path.
 *
 * Protection means "a signed-in person is required", and nothing more:
 * deciding who may do what stays in the app.
 */
enum Protection: string
{
    /** Needs a session. No session: send the browser to sign in. */
    case Login = 'login';

    /** Needs a session. No session: answer 401, because a script cannot follow a login redirect. */
    case Api = 'api';

    /** Passed through without a session. No identity is attached. */
    case Open = 'public';
}
