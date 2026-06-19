# API

## JavaScript Collector

`pixl77.js` sends JSON via `POST` to:

```text
/stats3/pixl_collect.php
```

The collector accepts `text/plain` JSON so cross-origin requests can be sent without a preflight request in common browser setups.

## Tracking Pixel

```text
GET /stats3/pixl_collect.php?pixel=1
```

Returns a transparent 1x1 GIF and stores a `PIXEL` event. If no `url` or `path` is supplied, the collector uses the request referrer when available.

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
