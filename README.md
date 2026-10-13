<div align="center">

# 🔐 OpenFGA for PHP

**Fine-grained authorization, with a DSL parser nobody else has.**

Checks, relationship tuples, authorization models written in `.fga` —
for Laravel, Symfony, or no framework at all.

[![PHP](https://img.shields.io/badge/PHP-8.2%20%E2%86%92%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![OpenFGA](https://img.shields.io/badge/OpenFGA-1.x-7D4CDB)](https://openfga.dev)
[![Tests](https://img.shields.io/badge/tests-355%20%2B%2030%20live-22C55E)](#-tests)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-2563EB)](#-tests)
[![License](https://img.shields.io/badge/license-MIT-blue)](#license)

</div>

---

[OpenFGA](https://openfga.dev) is a CNCF authorization engine built on Google's
Zanzibar paper: you describe your permissions as a graph of relationships, write
facts into it, and ask it questions. *Can Anne view this document?* — answered
in milliseconds, whether the answer comes from one tuple or from six hops
through a folder hierarchy and two group memberships.

This package speaks all of it: the twenty-odd endpoints, the three
authentication modes, the streaming queries, and — the part that does not exist
anywhere else in PHP — **the DSL**.

> ### Everything below was checked against a real OpenFGA server
>
> OpenFGA is open source and starts in one command, so unlike a hosted platform
> there is no excuse for an SDK that has never spoken to it. This one has a
> [live test suite](#-tests) that runs in CI against **OpenFGA v1.21.0** on
> every push, and the DSL parser is additionally held to
> [OpenFGA's own 29 reference fixtures](#-the-dsl).
>
> Where the documentation and the server disagree, this README says what the
> server does.

## 🕳️ The gap this fills

There is **no official OpenFGA SDK for PHP**. Measured rather than asserted:

| | |
| :-- | :-- |
| Official SDKs in the `openfga` org | `go`, `js`, `python`, `java`, `dotnet` — all pushed within the last week |
| Official PHP SDK | **none** |
| `evansims/openfga-php` | **archived on GitHub**, marked `abandoned` on Packagist, last release July 2025, 6 open issues |
| `evansims/openfga-laravel` | **archived**, one release ever, ~3 downloads a month |

So: no first-party option, and the community one was put down. This package
starts from PHP **8.2** rather than 8.3, so it installs where the archived one
would not.

## ✨ What's inside

- 📝 **A real DSL parser** — `.fga` files to JSON and back, written against
  OpenFGA's published ANTLR grammar and checked against their own reference
  fixtures. **No other PHP package does this**; the reference transformer
  exists once, in TypeScript, as a separate tool
- ⚡ **The five questions** — `check`, `batchCheck`, `listObjects`,
  `listUsers`, `expand` — with [a paragraph on which to use](#-the-five-questions),
  because picking wrong is what makes an integration slow
- 🌊 **Streaming** — `streamListObjects` reads a result set larger than memory,
  and is not capped by the server's list limit
- 🧩 **Conditions (ABAC)** — time-limited grants, IP ranges, anything CEL can
  express, with the context passed per check
- 🔮 **Contextual tuples** — "could Anne see it *if* we added her to this
  group?", answered without writing anything
- 🔑 **All three auth modes** — none, pre-shared key, OAuth2 client
  credentials with early refresh and jitter
- 🧰 **Laravel**: a Gate hook, route middleware, an Eloquent trait.
  **Symfony**: a Security voter, so `#[IsGranted('viewer', subject: 'document')]`
  just works. Both **abstain** for anything that did not opt in
- 🧊 **No retries, no magic, no failing open** — and
  [a paragraph on each](#-four-things-this-sdk-refuses-to-do)
- 🧪 **355 tests** plus **30 against a live server**, PHPStan at **max**

## 🚀 Quick start

```sh
composer require xpiston/openfga
```

You also need any PSR-18 client. If you have none:

```sh
composer require symfony/http-client nyholm/psr7 php-http/discovery
```

Start a server — this is the whole setup, there is no account to create:

```sh
docker run -d -p 8080:8080 openfga/openfga run
```

```php
use Piston\OpenFga\OpenFga;

$fga = OpenFga::at('http://localhost:8080');

// Once, in a deployment script.
$store = $fga->stores()->create('my-app');
$fga = $fga->withStore($store->id);

$modelId = $fga->models()->write(file_get_contents('authorization-model.fga'));
```

Then, for the rest of the application's life:

```php
$fga = OpenFga::at('http://localhost:8080', store: $storeId, model: $modelId);

$fga->grant('user:anne', 'owner', 'document:budget');

$fga->check('user:anne', 'viewer', 'document:budget');   // true - owner implies viewer
$fga->check('user:bob',  'viewer', 'document:budget');   // false
```

That `model:` argument is the most important one in this README. See
[the model id](#-pin-the-model-id).

## 📝 The DSL

Nobody writes an authorization model in JSON. They write it in OpenFGA's DSL,
in a file next to the code it governs:

```
model
  schema 1.1

type user

type group
  relations
    define member: [user]

type folder
  relations
    define owner: [user]
    define viewer: [user, user:*, group#member] or owner

type document
  relations
    define parent: [folder]
    define owner: [user] or owner from parent
    define editor: [user, group#member] or owner
    define viewer: [user with non_expired_grant] or editor or viewer from parent
    define blocked: [user]
    define can_read: viewer but not blocked

condition non_expired_grant(current_time: timestamp, grant_time: timestamp, grant_duration: duration) {
  current_time < grant_time + grant_duration
}
```

The HTTP API accepts only JSON, and every official SDK leaves that gap — the
transformer lives once, in TypeScript, in a separate package. This closes it:

```php
use Piston\OpenFga\Dsl\Dsl;

$model = Dsl::file(__DIR__.'/authorization-model.fga');

$fga->models()->write($model);            // or pass the raw text, it is detected
echo Dsl::toJson($dslText);               // DSL  -> JSON
echo Dsl::stringify($jsonFromTheServer);  // JSON -> DSL
```

### What "checked" means here

Three claims, each with a test suite behind it:

| | |
| :-- | :-- |
| **DSL → JSON** matches OpenFGA's reference transformer | **29/29** of [their own fixtures](tests/Conformance/fixtures/NOTICE.md) — including the real GitHub, Google Drive, Slack and banking models, and a torture case with eight nested mixed-operator relations |
| **The round trip** holds, both ways | **29/29** for JSON → DSL → JSON, and **29/29** for DSL → DSL → JSON |
| **A real server agrees** | every model above deployed to OpenFGA v1.21.0, read back, and compared as a model |
| **Invalid DSL is refused** | the **50** syntax-error cases in their validation corpus |

Using OpenFGA's fixtures rather than mine is the whole point: a transformer
that agrees with examples its own author wrote has proved only that the author
is consistent.

### Syntax here, semantics there

This parser does **syntax**. The server does **semantics**, and that division
is deliberate rather than lazy. Three of OpenFGA's own transformer fixtures
parse perfectly here and are then refused by the server:

```
the 'document#relation4' relation is referenced in a tupleset
   and thus must be a direct relation
the relation type 'user#follower' on 'relation3' is not valid
condition 'doc_viewer_condition' - undeclared reference to 'document_attributes'
```

Those are model-validity and CEL-compilation rules. Re-implementing them in PHP
would be a second opinion that is sometimes wrong, so a condition's expression
is captured **verbatim** — braces and quotes respected, so OpenFGA's own
`x=="{}"` comes out right — and handed to the server to compile. There is
[a live test](tests/Live/ModelLifecycleTest.php) for the harder case the
fixtures do not cover: `x == "{"`, where a brace *counter* without string
awareness also breaks.

### It tells you where

```
a direct assignment "[...]" may only be the first thing in a relation
definition - write "[user] or owner" rather than "owner or [user]" on line 9:

    define viewer: owner or [user]
                            ^
```

The grammar has rules that surprise people, and each gets a message rather
than a token dump:

- **Operators do not mix.** `a and b or c` is a syntax error, not an implicit
  grouping — there is no precedence in this language, and inventing one would
  accept models the server rejects and silently change the meaning of others.
- **`but not` takes one right-hand side.** `a but not b but not c` has to be
  `a but not (b or c)`.
- **A direct assignment may only lead**, at any depth.
- **Containers do not nest.** `list<list>` is refused.

## ❓ The five questions

Which one you ask is the decision that decides whether your application is fast
or slow.

```php
// One yes/no. Cheap. Safe on the request path.
$fga->check('user:anne', 'viewer', 'document:budget');

// Many, in one call, answered in the order asked. What a list page needs.
$fga->query()->batchCheck([
    TupleKey::of('user:anne', 'viewer', 'document:1'),
    TupleKey::of('user:anne', 'viewer', 'document:2'),
]);

// "Which documents may Anne see?" Expensive, and capped - read on.
$fga->query()->listObjects('user:anne', 'viewer', 'document');

// The same, streamed: no cap, first result arrives immediately.
foreach ($fga->query()->streamListObjects('user:anne', 'viewer', 'document') as $document) {
    // ...
}

// "Who may see this document?" One kind of user per call - see below.
$fga->query()->listUsers('document:budget', 'viewer', 'user');          // subjects
$fga->query()->listUsers('document:budget', 'viewer', 'group#member');  // usersets

// Why. A tree, for debugging rather than for serving.
$fga->query()->expand('document:budget', 'viewer');
```

**`listUsers` takes one kind of user per call.** The wire field is an array, and
the OpenAPI definition declares no constraint on it — but the server answers two
elements with *"invalid ListUsersRequest.UserFilters: value must contain exactly
1 item(s)"*. So the signature takes a string: a list would have invited a 400 on
the second element. Measured on a live server, which is the only reason this
README does not say otherwise.

**`listObjects` is the one that surprises people in production.** It is bounded
by `--listObjects-max-results` (1000 by default) and `--listObjects-deadline`
(3s), and **it does not tell you it truncated**. For a page of objects you
already have, `batchCheck` is almost always right instead. For "show me
everything", use `streamListObjects`, which has no result cap.

`expand` deserves one note, because reading it wrong is easy. A relation
defined as `[user] or owner` expands into the directly assigned users **and** a
computed reference still to be followed — so `users()` alone is a half-truth,
and `usersets()` holds the other half:

```php
$tree = $fga->query()->expand('folder:budgets', 'viewer');

$tree->users();     // ['user:bob']            - assigned directly
$tree->usersets();  // ['folder:budgets#owner'] - and this, still to follow
```

## 🔗 Tuples

```php
use Piston\OpenFga\Model\TupleKey;

// One at a time.
$fga->grant('user:anne', 'owner', 'document:budget');
$fga->revoke('user:anne', 'owner', 'document:budget');

// Or atomically, writes and deletes together.
$fga->tuples()->write(
    writes: [TupleKey::of('user:anne', 'member', 'group:eng')],
    deletes: [TupleKey::of('user:bob', 'member', 'group:eng')],
);
```

A write is **transactional**: all of it or none of it. That is a feature — "add
Anne, remove Bob" is one change — and it is why a list over the server's
`--max-tuples-per-write` is **refused here rather than split**. Splitting would
turn one atomic change into several that can half-fail, which is worse than an
error.

Three shapes of user, and they are different things rather than variations:

```php
'user:anne'             // a subject
'group:eng#member'      // a userset: whoever is `member` of group:eng
'user:*'                // everybody of that type - read it twice
```

The third makes a relation public. Granting `[user:*]` grants the
unauthenticated, and it looks like a glob. The second is what makes the model
worth using: the grant follows the group's membership with no further tuples.

`user:*` is a perfectly good *user* and never a valid *object* — the server
refuses `document:*` outright — which is why this library has separate `User`
and `TypedObject` types rather than one `Identifier` that cannot say so.

### Reading is not checking

```php
foreach ($fga->tuples()->readAll('document:budget') as $tuple) {
    echo $tuple;   // document:budget#viewer@user:anne
}
```

`read` returns tuples that were **written**. It does no graph traversal, so a
document with no `viewer` tuples does not mean nobody can view it. There is
[a live test](tests/Live/AuthorizationTest.php) asserting exactly that
asymmetry, because conflating the two is a plausible and expensive mistake.

### Mirroring with the change log

```php
foreach ($fga->tuples()->changesSince($savedToken) as $change) {
    $change->isWrite() ? $index->add($change->key) : $index->remove($change->key);
}
```

Store the token, come back later, apply what happened. OpenFGA hands its final
token back unchanged with no new entries, which is an infinite loop for a reader
that only stops on a null token — this one stops.

## ⏱️ Conditions and contextual tuples

A condition turns a grant into a rule. The tuple carries what was agreed; the
check carries what is true now:

```php
$fga->tuples()->grant('user:dana', 'viewer', 'document:q4', 'non_expired_grant', [
    'grant_time' => '2026-10-13T09:00:00Z',
    'grant_duration' => '1h',
]);

$fga->check('user:dana', 'viewer', 'document:q4', ['current_time' => '2026-10-13T09:30:00Z']); // true
$fga->check('user:dana', 'viewer', 'document:q4', ['current_time' => '2026-10-13T11:00:00Z']); // false
```

Same tuple, two answers. And a check that omits the context the expression needs
is an **error**, not a denial — OpenFGA says so rather than quietly answering
no, and this library passes that through.

Contextual tuples answer a hypothetical without writing one:

```php
$fga->check('user:zoe', 'viewer', 'folder:budgets');   // false

$fga->check('user:zoe', 'viewer', 'folder:budgets', contextualTuples: [
    TupleKey::of('user:zoe', 'member', 'group:finance'),
]);                                                    // true

$fga->check('user:zoe', 'viewer', 'folder:budgets');   // false again - nothing was written
```

## 📌 Pin the model id

Models are **immutable and append-only**: writing one adds a version and returns
its id, and nothing is ever edited or deleted. That is what makes pinning safe,
and what makes *not* pinning quietly dangerous.

Without an id, OpenFGA answers every check against whichever model version is
newest. So somebody deploying a model change alters the answers a running
application gets — mid-request, with no deployment of its own and no way to
notice.

```php
OpenFga::at($url, store: $storeId, model: $modelId);   // do this
```

Get the id at deploy time, put it in configuration, change it on purpose.

## 🚫 Four things this SDK refuses to do

**It never retries.** Not a 429, not a 500. A check sits on the request path of
whatever it authorises, so a hidden retry turns one slow response into three.
More to the point, a write is transactional: a retried write that actually
succeeded the first time comes back as a duplicate error, so a retrying client
turns a success into a failure.

**It never treats a denial as a failure.** `{"allowed": false}` is a successful
call with a negative answer. The one thing this client must not do is conflate
that with a transport error, because that is how an application fails open — so
`check()` returns a bool, exceptions mean the question was not answered, and a
missing `allowed` field reads as a denial rather than as approval.

**It never takes over your authorisation.** Laravel's Gate hook and Symfony's
voter both **abstain** unless the object implements `FgaObject`. Installing this
package changes the behaviour of exactly nothing until a model asks it to. (An
earlier version checked the object before the ability, which made
`Gate::allows('viewAny', $unsavedModel)` throw in applications that worked
before — there is a test named after it.)

**It never puts credentials in a URL.** Header only.

```php
use Piston\OpenFga\Exception\OpenFgaException;    // the interface, for one catch
use Piston\OpenFga\Exception\ValidationError;     // 400/422 - usually a tuple that does not fit the model
use Piston\OpenFga\Exception\Unauthenticated;     // 401/403 - *this SDK* may not talk to OpenFGA
use Piston\OpenFga\Exception\NotFound;            // 404
use Piston\OpenFga\Exception\RateLimited;         // 429
use Piston\OpenFga\Exception\TransportFailed;     // the network, or a non-JSON body
use Piston\OpenFga\Exception\InvalidArgument;     // your mistake, before any request
```

Each carries OpenFGA's own `code` and `message`, because they are specific and
worth logging:

```php
try {
    $fga->check('user:anne', 'nonexistent', 'document:budget');
} catch (ValidationError $e) {
    $e->errorCode();   // 'validation_error'
    $e->apiMessage();  // "invalid relation: relation 'document#nonexistent' not found"
}
```

Note that `Unauthenticated` means *this SDK* was refused, which is a different
thing from a user being denied. Conflating them produces an application that
opens up when its credentials expire.

## 🧰 Laravel

The provider is auto-discovered.

```dotenv
OPENFGA_URL=http://localhost:8080
OPENFGA_STORE_ID=01HQ...
OPENFGA_MODEL_ID=01HQ...
```

Opt a model in, and the Gate answers:

```php
use Piston\OpenFga\Bridge\Laravel\Concerns\AuthorizedByOpenFga;
use Piston\OpenFga\Contracts\FgaObject;

final class Document extends Model implements FgaObject
{
    use AuthorizedByOpenFga;      // `document:<key>`, or set $fgaType
}
```

```php
Gate::allows('viewer', $document);
$this->authorize('can_read', $document);
```

Route middleware:

```php
Route::get('/documents/{document}', ShowDocument::class)
    ->middleware('fga:viewer,document');
```

401 when nobody is authenticated, 403 on a denial — and it **refuses to start**
if the route parameter it was told to read does not exist, because a middleware
that allowed a request it could not evaluate would be the worst possible bug in
this package.

Or just use the client:

```php
public function show(OpenFga $fga, Document $document)
{
    abort_unless($fga->check("user:{$request->user()->id}", 'viewer', $document->fgaObject()), 403);
}
```

`php artisan vendor:publish --tag=openfga-config` for the rest — including
`openfga.gate = false` to keep the Gate entirely yours.

## 🧰 Symfony

```php
// config/bundles.php
return [
    Piston\OpenFga\Bridge\Symfony\OpenFgaBundle::class => ['all' => true],
];
```

```yaml
# config/packages/openfga.yaml
openfga:
    url: '%env(OPENFGA_URL)%'
    store: '%env(OPENFGA_STORE_ID)%'
    model: '%env(OPENFGA_MODEL_ID)%'
```

The voter is registered automatically, so the attribute is the relation name:

```php
#[IsGranted('viewer', subject: 'document')]
public function show(Document $document): Response

// or
$this->denyAccessUnlessGranted('can_read', $document);
```

`ROLE_*` and `PUBLIC_ACCESS` are left to the voters that own them: only
lower-case, relation-shaped attributes are answered, and only for subjects
implementing `FgaObject`.

## ✅ What's verified, and how

| | |
| :-- | :-- |
| **The DSL parser** | 29/29 reference fixtures, three directions, plus 50 invalid-DSL cases, plus every model deployed to a live server and read back |
| **Every query** — check, batch, list, stream, expand | asserted byte-for-byte against a fake transport **and** run against OpenFGA v1.21.0 |
| **Conditions** | evaluated by the server's real CEL engine: the same tuple allowed at 09:30 and denied at 11:00 |
| **Transactional writes** | proved by making one half-invalid and asserting nothing was written |
| **Nanosecond timestamps** | the server sends nine fractional digits; the parser was fixed after being fed a value a live server had just produced |
| **Both bridges** | Laravel in an application booted by Testbench; Symfony compiling a **real** container, in five configurations |
| **The OAuth2 exchange** | shape taken from the official SDKs and asserted against a fake issuer. **Not** run against a real identity provider — the one thing here that is conformant-by-reading rather than measured |

## 🧱 How it's built

```
src/
├── OpenFga.php              five doors, plus check/grant/revoke on the front
├── Dsl/
│   ├── Dsl.php              parse, stringify, toJson, fromJson, file
│   ├── Parser.php           line-oriented; conditions scanned with quote awareness
│   ├── RelationExpression.php   the right-hand side of a `define`
│   ├── SyntaxError.php      a line, a column and a caret
│   └── Ast/                 Userset and its six shapes, types, conditions
├── Model/
│   ├── TypedObject.php      `document:budget` - never a wildcard
│   ├── User.php             `user:anne`, `user:*`, `group:eng#member`
│   ├── TupleKey.php         prints as `object#relation@user`, like the server
│   └── Timestamp.php        OpenFGA's formats and no others
├── Api/
│   ├── QueryClient.php      the five questions
│   ├── TupleClient.php      write, read, changes
│   ├── ModelClient.php      takes DSL text directly
│   ├── StoreClient.php
│   └── AssertionClient.php
├── Http/Client.php          auth, JSON, status codes, streaming. No retries.
├── Configuration/           endpoint, store id, and the three auth modes
├── Contracts/               FgaObject and FgaUser - framework-neutral
└── Bridge/{Laravel,Symfony}/
```

### Decisions visible in the code

- **`User` and `TypedObject` are separate types** because OpenFGA treats them
  separately: `user:*` is a valid user and `document:*` is refused as an
  object. One `Identifier` class cannot express that, so it finds out in
  production.
- **`Timestamp` parses OpenFGA's formats and nothing else.** Go emits nine
  fractional digits and PHP's `u` means six, so `createFromFormat` returns
  false on a real response — that bug would have silently nulled every tuple
  timestamp. `new DateTimeImmutable($value)` was not the fix: it accepts
  `"tomorrow"`, and an impossible date like `2026-02-30` rolls over into a
  plausible wrong one, so the parsed value is written back out and compared.
- **An empty request body is `{}`, never `[]`.** `json_encode([])` produces a
  JSON array, and the server answers one with `syntax error (line 1:1):
  unexpected token [` — which reads like a problem with a relation expression
  and is not. An unfiltered `read()` is the call that sends one.
- **`ApiError::from()` uses `new static`.** With `new self`, every subclass
  factory returned the parent and every `catch (ValidationError)` in every
  application would have been dead code. Found by catching a real 400 and
  noticing the catch block did not fire.
- **A relation's types live with the relation.** The wire format splits a
  relation's shape from the types it accepts, putting the second under
  `metadata.relations.<name>.directly_related_user_types` — so reading a model
  means holding two trees open. `Relation` is the join.
- **Empty shapes are exact.** A model with no types omits `type_definitions`
  entirely; a type with no relations emits `"relations": {}` **and**
  `"metadata": null`; a model with no conditions omits `conditions`. Nothing
  would reject the wrong answer, and it would still differ from every model
  OpenFGA has produced.
- **Protobuf zero values mean absent.** Read a model back and the gateway
  renders `condition: ""`, `module: ""`, `source_info: null`, `conditions: {}`.
  A reader that took `condition: ""` for a condition named `""` writes DSL the
  server then refuses.

## ✅ Tests

```sh
composer install
composer test        # 355 tests, no network
composer analyse     # PHPStan, level max
composer test:lint   # Pint

docker run -d -p 8080:8080 openfga/openfga run
composer test:live   # 30 tests, against a real server
```

The default suite needs nothing but PHP 8.2 and Composer, and runs in under
half a second. Four suites:

**`unit`** — each piece held still, with every request asserted in full against
a fake transport, plus the things a real server cannot be made to do on demand:
a 500, a body that is not JSON, a stream that stops halfway.

**`conformance`** — this parser against OpenFGA's own, in three directions,
plus their 50 syntax-error cases.

**`laravel`** — the bridge inside a real Laravel application, booted by
Testbench.

**`symfony`** — the bundle compiling a **real** Symfony container in five
configurations. It earned its place immediately: it caught a service definition
using `__construct` as a factory, which Symfony does not allow and which no
unit test would have noticed.

The **live** suite is separate on purpose — it needs a server, so a red run
there means "OpenFGA is not running" as often as "this is broken", and that is
not what a merge gate is for. It runs in CI against a real server on every
push. Each test creates its own store and deletes it afterwards.

Some tests are named after specific mistakes rather than specific features: the
one proving `'yesterday'` is not a timestamp, the one proving an empty body is
`{}`, the one proving each status maps to its own exception class, and the one
proving an unhandled Gate ability never touches the object.

## ⚠️ Not included

Deliberately:

- **Modular models** (`module`, `extend type`, `fga.mod`). The grammar is
  supported up to the point of recognising them, and they are refused with a
  message that says so rather than mis-parsed. Compose them with OpenFGA's CLI
  and parse the result.
- **A CEL parser.** Condition expressions are captured verbatim and compiled by
  the server, which reports on them precisely. A PHP re-implementation could
  only add a way to be wrong.
- **Semantic model validation.** Same reason: the server has the rules and
  states them clearly. See [syntax here, semantics there](#syntax-here-semantics-there).
- **The AuthZEN endpoints** (`/access/v1/evaluation` and friends). New in
  OpenFGA 1.21 and a different specification; they deserve their own release.
- **Private-key JWT client assertions.** The client-secret flow is what
  deployments use; the assertion flow can follow if somebody needs it.
- **Retries, backoff, caching.** [Above](#-four-things-this-sdk-refuses-to-do).

## 📚 What this was built from

- [OpenFGA documentation](https://openfga.dev/docs) ·
  [API reference](https://openfga.dev/api/service) ·
  [the OpenAPI definition](https://github.com/openfga/api)
- [The DSL grammar](https://github.com/openfga/language) — `OpenFGAParser.g4`
  and `OpenFGALexer.g4`, and the `tests/data` corpus vendored under
  [tests/Conformance/fixtures](tests/Conformance/fixtures/NOTICE.md)
  (Apache-2.0, © OpenFGA authors)
- [Configuration language](https://openfga.dev/docs/configuration-language) ·
  [Conditions](https://openfga.dev/docs/modeling/conditions) ·
  [Relationship queries](https://openfga.dev/docs/interacting/relationship-queries)
- Google's [Zanzibar paper](https://research.google/pubs/pub48190/), for why any
  of this is shaped the way it is

## License

MIT. See [LICENSE](LICENSE).
