// Bu bir canlı veri uygulaması (bakiye, gider vb. her zaman güncel olmalı),
// bu yüzden agresif önbellekleme yapmıyoruz. Bu service worker sadece
// tarayıcının "Ana Ekrana Ekle" / kurulum özelliğini etkinleştirmek için var.

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});
