# Chirper – Application Architecture & CRUD Flow

This document explains how the Laravel CRUD stack in this repo connects together: routes → middleware → controllers → policies → models/Eloquent → database → Blade views. It also walks through what happens when a user accesses the app and performs create, update, and delete actions for Chirps.


## Overview
- Entity: Chirp (short text post with author and timestamps)
- Auth: Simple email/password auth with Register/Login/Logout invokable controllers
- Authorization: Policy ensures only the author can edit/delete their chirps
- UI: Blade components for layout and for rendering each chirp card


## Data model

### Database table: `chirps`
Defined in `database/migrations/2025_10_17_070434_create_chirps_table.php`
- id (bigint, PK)
- user_id (nullable FK → users.id, cascade on delete)
- message (string, 255)
- created_at, updated_at (timestamps)

Note: `user_id` is nullable, so legacy/seeded “anonymous” chirps can exist. In UI, anonymous chirps render a placeholder avatar and name.

### Eloquent model: `App\Models\Chirp`
Defined in `app/Models/Chirp.php`
- `$fillable = ['message']` – only the message is mass-assignable
- `user(): BelongsTo` – inverse relationship to `App\Models\User`

### Eloquent model: `App\Models\User`
Defined in `app/Models/User.php`
- `$fillable = ['name','email','password']`
- `casts()` includes `password => 'hashed'`
- `chirps(): HasMany` – one-to-many relation to `Chirp`

### Seed data
Defined in `database/seeders/ChirpSeeder.php`
- Ensures 3 demo users and several sample chirps exist
- Uses `users->random()->chirps()->create([...])` to create chirps tied to a random user


## Routes and middleware
Defined in `routes/web.php`

Public route:
- `GET /` → `ChirpController@index` – shows the home feed with latest 50 chirps

Auth-protected routes (middleware: `auth`):
- `POST /chirps` → `ChirpController@store` – create a chirp
- `GET /chirps/{chirp}/edit` → `ChirpController@edit` – show edit form
- `PUT /chirps/{chirp}` → `ChirpController@update` – update chirp
- `DELETE /chirps/{chirp}` → `ChirpController@destroy` – delete chirp

Auth routes:
- `GET /register` → Blade view `auth.register` (middleware `guest`)
- `POST /register` → `Auth\Register` invokable controller (middleware `guest`)
- `GET /login` → Blade view `auth.login` (middleware `guest`)
- `POST /login` → `Auth\Login` invokable controller (middleware `guest`)
- `POST /logout` → `Auth\Logout` invokable controller (middleware `auth`)


## Controllers

### `App\Http\Controllers\ChirpController`
Key methods:
- `index()` – eager loads `user`, sorts by latest, takes 50, renders `resources/views/home.blade.php`
- `store(Request)` – validates `message` (required|string|max:255), creates via `auth()->user()->chirps()->create($validated)`, redirects back `/` with a success message
- `edit(Chirp $chirp)` – uses `$this->authorize('update', $chirp)` then renders `resources/views/chirps/edit.blade.php`
- `update(Request, Chirp $chirp)` – authorizes `update`, validates, calls `$chirp->update($validated)`, redirects `/` with success
- `destroy(Chirp $chirp)` – authorizes `delete`, calls `$chirp->delete()`, redirects `/` with success

### Auth invokable controllers
- `Auth\Register` – validates (name, unique email, password confirmation), creates `User`, `Auth::login($user)`, redirects with success
- `Auth\Login` – validates credentials, `Auth::attempt(...)` (with remember), regenerates session on success, redirects intended; on failure returns back with error
- `Auth\Logout` – `Auth::logout()`, invalidates & regenerates session token, redirects with success


## Authorization Policy

`App\Policies\ChirpPolicy`
- `update(User $user, Chirp $chirp)` → allows only if `$chirp->user()->is($user)`
- `delete(User $user, Chirp $chirp)` → allows only if `$chirp->user()->is($user)`
- Other policy methods are not used here and return `false`

`ChirpController` calls `$this->authorize('update', $chirp)` and `$this->authorize('delete', $chirp)` to enforce policy checks before edit/update/delete.


## Views & UI components

- Layout: `resources/views/components/layout.blade.php`
  - Navbar shows auth-aware actions (Sign In/Sign Up vs. username + Logout)
  - Displays a toast for `session('success')`
  - Yields page content via `$slot`

- Home: `resources/views/home.blade.php`
  - Displays a new-chirp form posting to `/chirps` with CSRF
  - Iterates `$chirps` and renders each via `<x-chirp :chirp="$chirp" />`

- Chirp card component: `resources/views/components/chirp.blade.php`
  - Shows avatar (user-specific via avatars.laravel.cloud or anonymous placeholder)
  - Shows name, created time (`diffForHumans()`), and “edited” label if updated
  - Shows Edit/Delete actions only to authorized users (`@can('update', $chirp)`)

- Edit form: `resources/views/chirps/edit.blade.php`
  - PUT form to `/chirps/{id}` with CSRF & `@method('PUT')`

- Auth views: `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`
  - Typical forms mapped to routes in `web.php`


## End-to-end lifecycle (what happens when a user accesses the app)

1) User opens `/` (Home)
- Route: `GET /` → `ChirpController@index`
- Controller queries: `Chirp::with('user')->latest()->take(50)->get()`
- View: returns `home` Blade, which uses `<x-layout>` and lists `<x-chirp>` components
- If the user is authenticated, navbar shows name + Logout; otherwise shows Sign In / Sign Up

2) User posts a new chirp
- UI: Home page form submits `POST /chirps` with `message`
- Middleware: `auth` protects this route; unauthenticated users are redirected to login
- Controller: `store()` validates `message` and calls `auth()->user()->chirps()->create($validated)`
- Database: inserts a row into `chirps` with `user_id`, `message`, timestamps
- Response: redirect to `/` with `session('success')`; toast is displayed in layout

3) User edits a chirp
- UI: On a chirp owned by the user, the card shows an Edit button linking to `GET /chirps/{id}/edit`
- Middleware: route is `auth`
- Controller: `edit(Chirp $chirp)` authorizes with policy, then renders edit form
- User submits form: `PUT /chirps/{id}` with new `message`
- Controller: `update()` authorizes with policy, validates, then `$chirp->update($validated)`
- Response: redirect to `/` with success toast; card may show “edited”

4) User deletes a chirp
- UI: If authorized, the chirp card shows a Delete form submitting `DELETE /chirps/{id}`
- Middleware: route is `auth`
- Controller: `destroy()` authorizes with policy and calls `$chirp->delete()`
- Database: row removed; if user was deleted earlier, `cascadeOnDelete` would also remove their chirps
- Response: redirect to `/` with success toast

5) Authentication flows
- Register: `GET /register` (guest only) → form → `POST /register` → creates user → logs in → redirect home
- Login: `GET /login` (guest only) → form → `POST /login` → attempts → on success regenerate session and redirect intended/home
- Logout: `POST /logout` (auth only) → logs out, invalidates & regenerates token → redirect home


## Error handling and validation
- Validation errors on forms are displayed next to inputs via Blade `@error` blocks
- Unauthorized access (policy failure) triggers 403 response (handled by Laravel’s authorization system)
- Unauthenticated access to protected routes redirects to login


## Notes & extension points
- Consider making `user_id` non-nullable to disallow anonymous chirps if desired
- Add pagination on the home feed instead of `take(50)`
- Add soft deletes to `Chirp` if you want reversible deletions
- Add events/notifications for new chirps, likes, or comments
- Extract a `ChirpRequest` FormRequest for re-usable validation

/**
 * User Flow and Connections in Chirper CRUD App
 *
 * 1. When a user visits the app (GET /):
 *    - The route '/' maps to ChirpController@index.
 *    - ChirpController@index fetches the latest chirps with their user using Eloquent (Chirp::with('user')->latest()->take(50)->get()).
 *    - The data is passed to the Blade view 'home.blade.php', which uses the layout and chirp card components to render the feed.
 *
 * 2. When a user fills the form and posts a chirp:
 *    - The form in 'home.blade.php' submits a POST request to '/chirps'.
 *    - This route is protected by 'auth' middleware (user must be logged in).
 *    - ChirpController@store validates the message, then calls auth()->user()->chirps()->create($validated).
 *    - This creates a new Chirp model instance, saving it to the database with the current user's ID.
 *    - After creation, the user is redirected back to '/' with a success message (shown as a toast in the layout).
 *
 * 3. Connections between models, controllers, and Blade files:
 *    - Models: User (hasMany Chirp), Chirp (belongsTo User).
 *    - Controllers: ChirpController handles listing, creating, editing, updating, and deleting chirps. Auth controllers handle login, register, logout.
 *    - Blade files: 'home.blade.php' for the feed and form, 'chirps/edit.blade.php' for editing, 'components/chirp.blade.php' for each chirp card, 'components/layout.blade.php' for the overall page structure.
 *    - Policies: ChirpPolicy ensures only the owner can edit or delete their chirps.
 *
 * 4. When a user edits or deletes a chirp:
 *    - Edit and Delete buttons are shown only to the owner (via @can('update', $chirp) in the chirp card component).
 *    - Edit links to GET /chirps/{id}/edit, which shows the edit form.
 *    - Update submits a PUT to /chirps/{id}, Delete submits a DELETE to /chirps/{id}.
 *    - Both actions are authorized by ChirpPolicy and handled in ChirpController.
 *
 * This flow ensures a clear connection between routes, controllers, models, database, and Blade views for a seamless CRUD experience.
 */
