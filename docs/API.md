# API

## JavaScript Collector

`pixl77.js` sends JSON via `POST` to:

```text
/stats3/pixl_collect.php
```

The collector accepts `text/plain` JSON so cross-origin requests can be sent without a preflight request in common browser setups.

Retries retain the original `eventId`, `sentAt` (including milliseconds) and payload.
Snapshots for one event are serialized with a database row lock. Older checkpoints
and duplicate final summaries cannot replace newer measurements or dispatch another
notification. A reload that reuses the same page row preserves its measured progress;
a later BFCache summary can advance it. The final phase is marked by reason `LEAVE`
or boolean `events.reached.LEAVE = true`, allowing a configured final reason label.

## ReadingScore in Stats3 notifications

The server calculates the same `reading_score` for the stored event and its
Pushover message, for example `ReadingScore: 50/100 (High)`. This is an estimate
of interaction intensity, not a measured percentage of text read.

The score is calculated separately for each page visit. The tracker starts a
new sample list on each page load; shared visitor/session identifiers do not
add scores across pages. With `pushover.reading_score_only` enabled, only
stored human final events (`LEAVE`, or an explicitly marked final summary;
`is_bot = 0`) with `reading_score >= 1` may send
automatic Pushover notifications, bypassing both timing limits. Events without
a score, including STAT4 milestones, are suppressed.

- `count.js`: use the last 50 `engagement.readingSamples`, each limited to
  0–1000. Ignore malformed and non-finite values. An explicit empty list means
  measured zero and takes priority over any legacy score.
- Older `pixl77.js` / `pixl6.js` payloads: if no usable sample list is available,
  use `engagement.readingScore` (0–50000), including scores recovered from a
  text message. Missing or invalid measurements produce
  `ReadingScore: n/a (no measurement)` and SQL `NULL`, rather than a false zero.
- Method `activity-v1`: normalize raw activity `r` with `100 * r / (r + 50)`.
  When a finite, nonnegative `sessionDuration` is supplied, cap the result at
  `floor(100 * min(sessionDuration, 60) / 60)`, then round to an integer.
  Thus 5, 15 and 30 seconds allow at most 8, 25 and 50 points. Elapsed time
  alone earns no points. Missing or invalid duration does not impose a cap.
- Labels: zero raw activity = `No activity`; otherwise scores 0–24 = `Low`,
  25–49 = `Moderate`, 50–74 = `High`, 75–100 = `Very high`.

`payload_json.engagement` retains `readingScoreRaw` and `readingScoreMethod`
alongside the normalized `readingScore`. Reprocessing these payloads uses the
raw value to avoid normalizing twice. No new database columns are needed.
Existing rows are not backfilled; older raw scores may still exceed 100 until
their event is updated. Hidden tabs and idle periods are not separately
measured by this formula; session duration is elapsed time only.

## Captcha statistics

`stats.php` ends with the global Captcha countdown, an estimated waiting time,
and successful/failed attempts for the current and last completed phase.
Date and country filters do not apply to the global gate. The interval counts
new visitors (24-hour inactivity window), not page reloads: with interval N,
visitor N+1 starts the phase. Time is estimated from new Captcha visitors since
the current settings took effect, including idle time; at least two observations
with elapsed time are needed.

While the phase is active, every new visitor receives a CAPTCHA, regardless of
the number of already-open challenges. Known visitors who were not selected for
this phase remain exempt. Selected visitors keep their challenge across reloads
until verification, ticket expiry, or the end of the phase. A `check` renews an
expired ticket for a selected visitor while that phase remains active. The ten-minute
ticket lifetime is not a reservation limit or a waiting period for other visitors.
Once `success_target` successful verifications have been recorded, the phase ends;
remaining challenges are released on their next status check. Row locking keeps
concurrent successes from closing or counting the same phase more than once.

`captcha.php` accepts `check`, `status`, `verify`, and `fail`. The updated
`count.js` reports puzzle misses as cumulative `failedAttempts` (0–10000) for
each page instance, identified by `attemptSource` (1–128 ASCII letters, digits,
`_` or `-`). A reload gets a new source; parallel tabs contribute independently.
The same source and its final count are also included with `verify` to recover
lost failure reports. Repeated or older counts from a source do not add duplicates.
Only valid, unexpired, unverified tickets for the current phase can add failures.
Expired/abandoned gates and network errors are not failed puzzle attempts.
Older clients without `attemptSource` retain a separate cumulative legacy bucket.
A ticket holds at most 256 source buckets; overflow returns an explicit
`attempt_source_limit` error without recording an incomplete verification.

The schema initializer preserves existing gate state, adds
`pixl_captcha_visitors.failed_attempts` and `failed_attempt_sources`, and creates
`pixl_captcha_stats` in the configured MySQL database. Existing legacy failure
counts and active tickets survive this upgrade. No older unrecorded failure
history can be reconstructed.
Changing settings resets current counters and the time sample, while keeping
the last completed phase. Upload `stats.php`, `pixl_captcha.php`, `captcha.php`,
`count.js`, `captcha.js`, `captcha.css`, and `pixl_schema.sql` together; refresh
cached `count.js` on the websites. Keep `captcha.css` beside the central
`captcha.js`. The stylesheet loads from that script's origin, including when
`count.js` is hosted on a third origin. A restrictive host CSP must allow that
stylesheet origin or the nonce propagated from the embedding script to the
stylesheet link. No inline style permission is needed. Failed, blocked, or
timed-out CSS leaves the page usable and dismisses the interaction gate.

## Tracking Pixel

```text
GET /stats3/pixl_collect.php?pixel=1
```

Returns a transparent 1x1 GIF and stores a `PIXEL` event. If no `url` or `path` is supplied, the collector uses the request referrer when available.

When `public_key` is configured, the pixel must provide the same public key as
the JavaScript collector. Missing, incorrect or non-string keys return HTTP 403
and store no event. The server never fills this key in automatically:

```text
GET /stats3/pixl_collect.php?pixel=1&siteKey=YOUR_PUBLIC_SITE_KEY
```

URL-encode the public key. In an HTML `src` attribute, write the separator as
`&amp;siteKey=...`. This is the public site key, not an administrator password,
database credential or notification token.

Optional parameters:

```text
url=https://example.com/page
path=/page
ref=https://example.com/referrer
```

## Direct Collector Probe

```text
GET /stats3/pixl_collect.php
```

Stores a `DIRECT` event and returns JSON. Useful for checking bot-like direct endpoint requests.
The same `siteKey` requirement applies when `public_key` is configured.

## STAT4 collector

```text
POST /stats3/stat4/collect.php
```

The tracker sends an immutable event UUID, visitor/session identity and optional
`occurredAt` Unix timestamp in milliseconds. A failed request can be retried with
the same UUID for up to 15 minutes; duplicate UUIDs do not add pageviews, clicks
or active seconds again. The collector allows five minutes of browser clock skew.
An event must have occurred within its visitor's 24-hour identity window, even
when a retry arrives after that visitor identity expires. Clients without
`occurredAt` retain the previous receive-time behavior.

Session bounce state is evaluated from the updated totals exactly once: more
than one counted page, any click, or at least 15 active seconds ends a bounce.

## Dashboard

```text
GET /stats3/pixl_stats.php
```

Password-protected HTML dashboard.

Filters:

```text
days=30
bot=all|bots|humans
```

## Notification Feed

```text
GET /stats3/pixl_stats.php?notify_feed=1&after=123
```

Returns authenticated JSON for new events after the given numeric event id. This endpoint uses the same dashboard login cookie and is consumed by the browser-notification button in `pixl_stats.php`.

## Real Web Push Admin

```text
GET /pixel_stats2.php
```

Password-protected admin page for real Push API subscriptions. Only logged-in stats admins can subscribe a device.

```text
GET /pixel_stats2.php?action=public_key
POST /pixel_stats2.php?action=subscribe
POST /pixel_stats2.php?action=unsubscribe
POST /pixel_stats2.php?action=send_test
```

The service worker must be uploaded next to the PHP files:

```text
GET /pixel_webpush_sw.js
```

After an admin device is subscribed, `pixl_collect.php` sends a server-side Web Push notification to active admin subscriptions after every newly inserted event.
