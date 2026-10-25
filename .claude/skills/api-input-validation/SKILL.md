---
name: api-input-validation
description: "Policy: every byte of external input is validated in src/Api before it reaches Play or the Engine. Use when adding or changing a controller, endpoint, request DTO, route, serializer or auth check under src/Api, when handling user-submitted story content or choice input, or when specifying an API surface in a spec."
---

# Policy — validate at the boundary

**Owner:** API owner. Changes to this skill need their sign-off.

> The Engine trusts its inputs. The API must therefore never pass unvalidated data into it.

That trust is what lets the Engine stay small and framework-free. It is only safe because
`src/Api` is the single place where untrusted data stops being untrusted.

## The shape

Every endpoint takes a **DTO with constraints**, not an array, not a raw `Request`.

```php
final readonly class MakeChoiceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $sessionId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Assert\Regex('/^[a-z0-9_-]+$/')]
        public string $choiceId,
    ) {}
}
```

Rules:

- DTOs live in `src/Api/Dto`, are `final readonly`, and have typed properties.
- Validate **before** calling into `Play`. A validation failure returns 422 with field
  errors and never reaches the Engine.
- No `$request->get()` straight into a service. No `json_decode` result passed along
  untyped.
- Constrain the shape *and* the size: `Length`, `Range`, `Count`, `Choice`. An unbounded
  string or array is a resource-exhaustion path.
- Identifiers are `Assert\Uuid` or a strict pattern — never free text concatenated into a
  query.

## Authored story content

Story YAML and the JSON condition/effect AST are the highest-risk input in the system:
user-authored, deeply nested, and interpreted by the Engine.

- Validate structure, depth and operator names in `Content` **before** compiling to the
  JSONB snapshot. Reject an unknown operator at authoring time, not at play time.
- Cap nesting depth explicitly. The Engine also guards recursion, but the boundary should
  reject it first with a usable error.
- Never `eval`, never build a closure from authored data, never interpolate authored
  strings into SQL or YAML.

## Auth and ownership

- Every session and story endpoint checks **ownership**, not just authentication. "Is
  there a user?" is not the question; "does this user own this session?" is.
- Authorization lives in `src/Api/Security`. Do not scatter ownership checks through
  controllers.
- A missing ownership check is a review blocker.

## Responses must not leak

- No stack traces, class names, SQL, file paths or internal ids in an error response.
- No secrets or PII in logs. Log the session id, not the payload.
- Map domain exceptions to status codes deliberately:
  `ChoiceUnavailableException` → 409, `UnknownSceneException` → 404,
  `MalformedNodeException` → 500 (it means bad stored content, not bad user input) —
  and never let an `EngineException` serialize its message straight to the client.

## Tests

Every endpoint needs, at minimum:

- a success case;
- a validation-failure case per constraint that matters, asserting 422 and the field;
- an unauthenticated case and a **wrong-owner** case;
- a case proving malformed input never reaches the Engine.

Functional tests go under `tests/Integration/Api/`.

## Check

```bash
composer test
composer phpstan    # level 8: an untyped array crossing the boundary will show up here
```
