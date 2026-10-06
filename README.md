# UniGo — Integrated Smart Transport System (Prototype)

A working PHP/PDO/MySQL prototype of the system described in the case
study, built to demonstrate every core module for the project defense.

## Modules included

| Module | Files | What it demonstrates |
|---|---|---|
| Authentication | `auth/` | Register/login as passenger, driver, or operator; bcrypt password hashing |
| Passenger app | `passenger/` | Search routes, compare live fares across vehicle types (solo vs shared), book a seat, pay, track a trip live, send an SOS alert |
| Driver tools | `driver/` | View assigned trips, start/complete a trip, push simulated GPS pings |
| Admin / operator dashboard | `admin/` | Network stats, vehicle & fleet management, route/fare management, subscription billing, AI insight layer |
| Live tracking | `api/get_location.php`, `driver/update_location.php` | Passenger page polls every 5s for the vehicle's latest GPS ping |
| AI insight layer | `admin/ai_insights.php`, `estimate_congestion_score()` in `includes/functions.php` | Rule-based congestion forecast computed from recent GPS speed data — the "prediction" AI role from the case study, in a form a course project can demo without a full ML pipeline |
| Billing / subscriptions | `admin/subscriptions.php`, `calculate_subscription_due()` | Base fee + per-vehicle fee + distance fee, scaled by an operator's economy-adjustment index (Section 10 of the case study) |

## Database

The full schema is in `sql/schema.sql` — 13 tables (`users`,
`operators`, `vehicles`, `drivers`, `routes`, `route_stops`, `fares`,
`trips`, `gps_pings`, `bookings`, `payments`, `sos_alerts`,
`subscriptions`, `ai_insights`), plus seed data so every module has
something to show immediately.

## Live Google Map tracking

`passenger/track.php` and `driver/trips.php` (its active-trip mini-map) both
render a real Google Map with a marker for the vehicle, updated every time a
new GPS ping is polled/reported — the coordinates were already there, this
just visualizes them instead of showing raw numbers.

To turn it on:

1. In [Google Cloud Console](https://console.cloud.google.com/google/maps-apis),
   create a project (or use an existing one), enable the **Maps JavaScript
   API**, and create an API key.
2. Restrict the key to your domain (HTTP referrers) once you're past local
   testing.
3. Paste it into `GOOGLE_MAPS_API_KEY` in `config/config.php`.

Without a key set, both pages fall back to showing plain latitude/longitude
text (no broken map, no console errors) — so the rest of the demo still
works if you present before setting one up.

## Setup (XAMPP / WAMP / LAMP / phpMyAdmin)

1. Copy the `unigo/` folder into your web root (e.g. `htdocs/unigo`).
2. In phpMyAdmin, import `sql/schema.sql` — this creates the `unigo_db`
   database, all tables, and seed data.
3. Open `config/db.php` and set `DB_USER` / `DB_PASS` to match your
   MySQL setup (defaults to `root` / empty password, the typical XAMPP
   default).
4. If your local URL isn't `http://localhost/unigo`, update
   `BASE_URL` in `config/config.php` to match your folder/vhost name.
5. Visit `http://localhost/unigo/index.php`.

### Demo logins (password: `Password123`)

- **Admin:** admin@unigo.africa
- **Passenger:** grace@example.com
- **Driver:** peter@example.com
- **Operator:** ops@kayola.co.ug

## Suggested demo flow for the defense

1. Log in as **Grace (passenger)** → Find a trip → pick the Kampala–
   Entebbe route → compare solo vs shared fares across vehicle types →
   book the electric bus → pay → open **Track** to see the live-polling
   location page.
2. In another browser/incognito window, log in as **Peter (driver)** →
   My trips → click "Report current location" a few times to push GPS
   pings — watch the passenger's tracking page update.
3. Log in as **admin** → Dashboard for network stats → **AI insights**
   → "Run congestion forecast now" to show the prediction layer acting
   on the GPS pings just generated → **Billing** → "Recalculate" to
   show the distance-based subscription formula in action.

## Notes on scope

This is a demonstrable MVP, not a production system — GPS reporting
is simulated by a button (or the browser's geolocation API) rather
than a real hardware GPS device, the AI congestion model is a
transparent rule-based score rather than a trained model, and payments
are simulated rather than wired to a real mobile-money gateway. The
architecture (separate modules, a generic route/vehicle data model,
country/district columns on `routes` and `operators`) is what the case
study's scalability answer (Q14) refers to — new areas are added as
data, not new code.
