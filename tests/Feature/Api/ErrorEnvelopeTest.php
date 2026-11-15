<?php

/**
 * The error shape, locked down.
 *
 * Every render hook in bootstrap/app.php answers in Laravel's own
 * `{ message, errors: { field: [...] } }`. The frontend reads `message` for its
 * toasts and `errors` keyed by field to put messages under inputs, so a
 * regression here is silent on the server and shows up as a form that cannot
 * report why it was rejected.
 */

use App\Models\User;

it('reports validation failures keyed by field', function () {
    actingAsAdmin();

    $response = $this
        ->postJson('/api/v1/users', [
            'name' => '',
            'email' => 'not-an-email',
        ])
        ->assertStatus(422);

    $body = $response->json();

    expect($body)->toHaveKeys(['message', 'errors'])
        ->and($body['errors'])->toBeArray()
        ->and(array_is_list($body['errors']))->toBeFalse()
        ->and($body['message'])->toBeString();

    // Each key is a field name and each value a list of messages, which is what
    // react-hook-form's error mapping expects.
    foreach ($body['errors'] as $field => $messages) {
        expect($field)->toBeString()
            ->and($messages)->toBeArray()
            ->and($messages[0])->toBeString();
    }
});

it('answers an unauthenticated request with a message and no errors array', function () {
    $response = $this->getJson('/api/v1/users')->assertStatus(401);

    expect($response->json('message'))
        ->toBe('You must be authenticated to access this resource.')
        ->and($response->json())->not->toHaveKey('errors');
});

/**
 * The case the test above could not see, because `getJson()` sets the header
 * that hides it.
 *
 * A guest whose request does NOT say it wants JSON would be redirected to
 * `route('login')`, which does not exist on this backend: the sign-in page is
 * the Next app on another host. `redirectGuestsTo(fn () => null)` in
 * bootstrap/app.php is what makes it a 401, and this is what stops it going back.
 */
it('answers a guest with 401 even when the request does not ask for JSON', function () {
    $response = $this->get('/api/v1/users')->assertStatus(401);

    expect($response->json('message'))
        ->toBe('You must be authenticated to access this resource.');
});

it('does not leak the model class from a 404', function () {
    actingAsAdmin();

    $response = $this->getJson('/api/v1/users/does-not-exist')->assertStatus(404);

    // Laravel's own text is "No query results for model [App\Models\User] ...",
    // which names an internal class and reads like a stack trace.
    expect($response->json('message'))->toBe('The requested record was not found.')
        ->and($response->json('message'))->not->toContain('App\Models')
        ->and($response->json('message'))->not->toContain('query results');
});

it('answers 403 when the caller lacks the permission', function () {
    // A signed-in user with no role at all: the `can:` middleware should stop
    // them, and the response should still be a plain message.
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/v1/users')->assertStatus(403);

    expect($response->json('message'))->toBeString();
});

it('never returns a JSON:API errors array on any failure', function () {
    actingAsAdmin();

    $failures = [
        $this->postJson('/api/v1/users', []),           // 422
        $this->getJson('/api/v1/users/nope'),           // 404
        $this->getJson('/api/v1/no-such-endpoint'),     // 404
    ];

    foreach ($failures as $response) {
        $body = $response->json();

        expect($body)->toHaveKey('message');

        // A JSON:API shape would be a *list* of error objects. Laravel's
        // `errors` is a map of field to messages, so a list here is wrong.
        if (array_key_exists('errors', $body)) {
            expect(array_is_list($body['errors']))->toBeFalse();
        }
    }
});
