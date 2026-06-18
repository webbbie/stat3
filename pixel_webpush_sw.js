self.addEventListener("push", (event) => {
  let payload = {};

  if (event.data) {
    try {
      payload = event.data.json();
    } catch {
      payload = { body: event.data.text() };
    }
  }

  const title = payload.title || "Pixl Event";
  const options = {
    body: payload.body || "Neuer Pixl-Eintrag",
    tag: payload.tag || `pixl-event-${Date.now()}`,
    renotify: true,
    requireInteraction: false,
    silent: false,
    data: {
      url: payload.url || "pixel_stats2.php",
      eventId: payload.eventId || 0
    }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();

  const targetUrl = new URL(event.notification.data?.url || "pixel_stats2.php", self.location.origin).href;

  event.waitUntil((async () => {
    const windows = await clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const client of windows) {
      if (client.url === targetUrl && "focus" in client) {
        return client.focus();
      }
    }
    return clients.openWindow(targetUrl);
  })());
});
