# Spec: AI Chat Endpoint Rate Limiting

## Objective

Make rate limiting for the existing `POST /ai-chat` endpoint explicit, named, and regression-tested without changing the AI Chat workflow or any other endpoint.

The route currently uses `throttle:30,60`. In Laravel's numeric throttle syntax, that means **30 attempts per 60 minutes**. This spec preserves that effective policy while moving the limit definition to a named Laravel limiter.

## Target endpoint

- Route name: `ai-chat.chat`
- Method/URI: `POST /ai-chat`
- Current route: `routes/web.php`
- Current protection: the protected CRM route group plus `throttle:30,60`
- Current handler: `AiChatController::chat()` → `AiAssistantService::reply()`
- Current client: `resources/views/crm/ai-chat/_widget.blade.php`, which expects JSON and reads `data.message` on errors

## Functional contract

### Limiting policy

- Maximum: **30 requests per 60-minute window**.
- Bucket key: authenticated user ID.
- The endpoint is already inside the authenticated CRM route group; unauthenticated access remains denied by existing middleware before the AI handler.
- A request that reaches the throttle middleware consumes one attempt, including requests that later fail validation or provider handling. This preserves the current middleware behavior.
- No IP-only or branch-wide bucket is introduced.

### Hook points

1. Register a named limiter in `app/Providers/AppServiceProvider.php` using Laravel's `RateLimiter::for()` and `Limit::perHour(30)->by($request->user()->id)` API.
2. Replace only the `ai-chat.chat` route's numeric throttle with `throttle:ai-chat` in `routes/web.php`.
3. Do not add throttling logic to `AiChatController`, `AiAssistantService`, the Blade widget, or a new custom middleware.

### Cache configuration

- Use Laravel's existing rate-limiter cache configuration.
- Do not add a new environment variable or cache store for this change.
- Do not introduce Redis, Reverb, queues, or another infrastructure dependency.
- The repository's default cache store is database-backed; PHPUnit overrides cache to the array store.

### Error response

When the limit is exceeded:

- Return HTTP `429 Too Many Requests`.
- Preserve Laravel's standard throttle error message (`Too Many Attempts.`) and rate-limit headers.
- Preserve `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`, and `X-RateLimit-Reset` when Laravel supplies them.
- Because the AI widget sends `Accept: application/json`, the response must remain JSON-compatible with its existing `parseResponse()` behavior and expose a `message` value.
- Do not add a new localized error format, toast system, or frontend error state in this change.

## What must not change

- AI input validation, including `message`, `conversation_id`, and `ai.max_input_length`.
- Conversation ownership, branch assignment, persistence, message truncation, tool-result sanitization, and provider fallback behavior.
- AI authorization, organization/branch scope, maintenance behavior, and the existing protected CRM middleware group.
- The GET history/show routes and DELETE conversation route.
- Rate limits on comments, presence, notifications, authentication, feedback, imports, or any other endpoint.
- The existing client-side request/response handling in `_widget.blade.php`.
- Cache drivers, database schema, environment variables, external AI provider configuration, and dependencies.

## Tech stack and project structure

- PHP `^8.3`, Laravel Framework `^13.8`, PHPUnit `^12.5`.
- Routes: `routes/web.php`.
- Service-provider configuration: `app/Providers/AppServiceProvider.php`.
- Controller/service: `app/Http/Controllers/Crm/AiChatController.php`, `app/Services/AiAssistantService.php`.
- UI contract: `resources/views/crm/ai-chat/_widget.blade.php`.
- Feature coverage: `tests/Feature/AiChatTest.php`.

## Code style

Use the existing provider and route style:

```php
RateLimiter::for('ai-chat', function (Request $request): Limit {
    return Limit::perHour(30)->by($request->user()->id);
});
```

Use named routes and middleware; keep controllers thin. Do not implement a second limiter abstraction.

## Testing strategy

Extend `tests/Feature/AiChatTest.php` with focused coverage for:

1. Requests within the limit continue to execute the existing AI Chat flow.
2. The 31st request for the same authenticated user within the window returns JSON HTTP 429 with the expected message/headers.
3. A different authenticated user has an independent bucket.
4. Existing AI Chat authorization and validation tests remain green.
5. The route uses the named `ai-chat` limiter and no other AI Chat route is throttled by this change.

Use the existing test fakes/mocks so tests do not call an external AI provider. Clear or isolate limiter state between examples using the project's existing cache/test conventions.

## Commands

```bash
php artisan test tests/Feature/AiChatTest.php
vendor/bin/pint --dirty --test
git diff --check
php artisan route:list -v --name=ai-chat
```

Run `php artisan optimize:clear` and `php artisan view:cache` only if route/provider/view changes require cache verification. No frontend build is required unless the Blade widget is changed.

## Boundaries

- **Always:** Preserve the existing 30-per-60-minute behavior, use the documented Laravel named-limiter API, test 429 behavior and bucket isolation, and keep JSON compatibility.
- **Ask first:** Changing the threshold/window, customizing the error message, changing cache stores, or adding rate-limit configuration to other endpoints.
- **Never:** Add controller-level counters, trust client-provided user/branch identity, expose provider credentials, broaden AI data scope, or alter unrelated route limits.

## Success criteria

- `ai-chat.chat` uses a named `ai-chat` limiter configured for 30 requests per hour per authenticated user.
- The existing AI Chat success, validation, authorization, provider fallback, persistence, and UI behavior are unchanged.
- Exceeding the limit produces a JSON-compatible HTTP 429 with Laravel's standard throttle headers.
- Focused AI Chat tests and formatting/route checks pass.
- No unrelated files, routes, configuration, dependencies, or database schema are changed.

## Sources and verification notes

- Laravel 13 documents named limiters via `RateLimiter::for()`, `Limit::perHour()`, `by()`, and `throttle:name`: <https://laravel.com/docs/13.x/routing#rate-limiting>
- Laravel 13 documents that rate limiting normally uses the default cache store and supports a separate `cache.limiter` override: <https://laravel.com/docs/13.x/rate-limiting#cache-configuration>
- Laravel 13 documents automatic HTTP 429 responses when a route exceeds its configured limit: <https://laravel.com/docs/13.x/routing#defining-rate-limiters>
- Laravel's installed 13.x source confirms numeric `throttle:30,60` multiplies the second argument by 60 seconds and emits the standard throttle headers. This implementation detail is verified locally in `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php`.
- The exact application-level JSON serialization of `ThrottleRequestsException` is not confirmed from the official rate-limiting documentation alone; the focused feature test must verify that OASIS's `expectsJson()` handling exposes the required `message` field to the AI widget.

## Open questions

None for this scoped change. The threshold is intentionally inherited from the current route rather than newly chosen.
