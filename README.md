# XHTTP PHP Reverse Proxy for Xray

This repository provides a single `app.php` script that allows you to use a standard PHP web host (cPanel, etc.) as an Xray XHTTP “packet-up” forwarder and SSE-style downlink.  Incoming XHTTP requests to `https://yourdomain.com/app.php/<UUID>` (GET) and `.../app.php/<UUID>/<seq>` (POST) are proxied verbatim to your Xray server over HTTP, preserving path segments and all `X-…` headers (e.g. `X-Padding`).

---


## Note

CPU & Latency: Streaming and header processing can be CPU-intensive. On low-powered hosts you may see increased ping and higher latency.

---

## Features

- **Path-preserving proxy**  
  Forwards `/app.php/<UUID>` and `/app.php/<UUID>/<seq>` to your Xray inbound without changing the URL.
- **Header forwarding**  
  Relays all incoming headers except `Host` and `Accept-Encoding`, and preserves `X-Padding` and other `X-…` response headers.
- **Session unlock**  
  Calls `session_write_close()` immediately to avoid PHP session lock blocking parallel SSE/POST requests.
- **Lightweight cURL**  
  Uses built-in cURL for minimal dependencies.
- **Simple logging**  
  Appends request/response info to `proxy.log` for debugging.

---

## Prerequisites

- PHP 7.4+ with cURL support
- Apache (or compatible) web server
- Xray server with an inbound configured for XHTTP (`network: xhttp`) listening on `164.92.226.103:2083` (example)
- DNS A record for `upx.mjsd.ir` (or your hostname) pointing to your PHP host

---

## Installation

1. **Upload** `app.php` to your web root (e.g. `public_html/app.php`).
2. **Ensure** `app.php` and the `proxy.log` file are writable by PHP.
3. **Add** the recommended `.htaccess` settings (see below) to disable buffering/compression and extend timeouts.

---

## Configuration

Open `app.php` and adjust:

```php
// === Configuration ===
$remoteHost       = '1.2.3.4'; /// your remote host
$remotePort       = 1234; /// your remote port
$remoteBasePrefix = '/app.php'; /// your remote base path
$remoteHostHeader = 'example.com'; /// your remote host header
$logFile          = __DIR__ . '/proxy.log';
$loggingEnabled   = true; /// enable or disable logging
````

Optionally, change the path prefix or enable logging by editing the `$logFile` path within the script.

---

## .htaccess Snippet

Place this in the same directory as `app.php` to optimize for long-polling/SSE:

```apache
<IfModule mod_deflate.c>
    SetEnv no-gzip 1
    SetEnv dont-vary 1
</IfModule>

<IfModule mod_headers.c>
    Header unset Pragma
    Header unset Cache-Control
    Header set Cache-Control "no-store"
    Header set X-Accel-Buffering "no"
</IfModule>

<IfModule mod_fcgid.c>
    FcgidIOTimeout 3600
    FcgidBusyTimeout 3600
</IfModule>

<IfModule mod_reqtimeout.c>
    RequestReadTimeout header=20-40,MinRate=500 body=20,MinRate=500
</IfModule>

<Files "app.php">
    SetEnvIf Request_URI "^/app\.php/" no-gzip dont-vary
    Timeout 3600
</Files>
```

---

## Usage

On your Xray client, configure an inbound like:

```jsonc
{
  "port": 2083,
  "protocol": "vless",
  "settings": {
    "clients": [ { "id": "YOUR-UUID", "flow": "", "email": "" } ],
    "decryption": "none",
    "fallbacks": []
  },
  "streamSettings": {
    "network": "xhttp",
    "security": "none",
    "xhttpSettings": {
      "host": "domain.com",
      "path": "/app.php",
      "mode": "packet-up",
      "scMaxEachPostBytes": 4096,
      "scMaxBufferedPosts": 10,
      "scStreamUpServerSecs": "20-80",
      "xPaddingBytes": "100-1000",
      "headers": {}
    }
  }
}
```

---

## Troubleshooting

* **413 Payload Too Large**
  Increase `scMaxEachPostBytes` in your Xray config to match your maximum POST chunk size.
* **Blocked parallel requests**
  Ensure sessions are closed early (`session_write_close()`) and your `.htaccess` disables buffering.
* **400 Bad Request**
  Check that `xhttpSettings.host` and `path` in Xray match exactly the proxy domain/path, and confirm headers forwarded correctly.

---

## TODO

 
* [ ] Optimize CPU usage and overall resource consumption
* [ ] Reduce latency by tuning buffer and flush strategies
* [ ] Add configurable rate-limiting and back-pressure controls
* [ ] Introduce asynchronous I/O or multi-process handling
* [ ] improved error reporting



