# Frontend hosting (static SPA)

The frontend is a static Vite build (`dist/`) using React Router `BrowserRouter`. Deep links such as
`/admin/orders`, `/my-books`, `/read/2`, `/books/2`, `/checkout/2`, `/orders/5/result`, `/account` and `/track`
have no file on disk, so the host must answer them with `index.html` (an "SPA fallback"). Without it, a refresh or a
shared link on any page but `/` is a server 404. Hosting is still open (U7); rules for each likely host ship in the build.

## 1. Build

`VITE_API_URL` is read at **build** time and baked into the bundle. It must be set for the `build` command itself:

```bash
npm ci
VITE_API_URL=https://<live-api-url> npm run build      # bash
# PowerShell: $env:VITE_API_URL="https://<live-api-url>"; npm run build
```

No trailing slash. Unset, the bundle falls back to `http://localhost:8080`; check with
`grep -l localhost:8080 dist/assets/*.js` (must print nothing). Base path is `/`: the site must be served from a
domain root (or sub-domain), not a sub-folder. A sub-folder needs `base` in `vite.config.js` plus matching rules.

Then upload the **contents** of `dist/` (including the dotfile `.htaccess`) to the web root.

On the API, `FRONTEND_ORIGIN` must equal the site origin exactly: scheme + host (+ port), no path, no trailing
slash, e.g. `https://www.example.com`. `https://example.com` and `https://www.example.com` are different origins:
pick one, redirect the other to it, and put that one in `FRONTEND_ORIGIN`, or every API call fails CORS.

## 2. Per host

**Apache / shared hosting (Hostinger, cPanel)**: `dist/.htaccess` does it. It serves real files and directories as
is, returns a real 404 for missing `/assets/*` bundles, and rewrites everything else to `index.html`. It also sets
`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `no-cache` on `index.html` and a one-year immutable
cache on hashed bundles. Every block is wrapped in `<IfModule>`. The host must allow `.htaccess` overrides
(`AllowOverride FileInfo`, the default on shared hosting). Some file managers hide dotfiles: confirm `.htaccess` was
uploaded.

**Netlify, Cloudflare Pages**: `dist/_redirects` (`/* /index.html 200`) does it. Static files take priority over the
rule, so assets are unaffected. Optional headers go in a `_headers` file (not shipped).

**nginx**:

```nginx
root /var/www/vtee/dist;
location / {
    try_files $uri $uri/ /index.html;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
location = /index.html {
    add_header Cache-Control "no-cache";
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
location /assets/ {
    try_files $uri =404;   # a missing bundle is a 404, not index.html
}
```

(nginx drops parent `add_header` lines in any location that sets its own, hence the repeats.)

**Other static hosts** (Vercel, S3/CloudFront, Firebase): configure "rewrite all to `/index.html`" in their own
config; neither shipped file applies.

## 3. Unknown paths

With the fallback, the server answers every unknown path with `index.html` and **HTTP 200**; the app then shows its
own "Page not found" screen (catch-all route in `src/App.jsx`). Search engines may see these as soft 404s; acceptable
for this site. Protected pages need no server rule: `/admin/*` shows the admin login without an admin session, and
`/my-books`, `/read/:id`, `/orders/:id/result` show a "Log in" link without a customer session.

## 4. Check after deploy

- Open `https://<site>/admin/orders` directly and refresh: admin login (or orders), not a host 404 page.
- Open `https://<site>/nope`: the app's "Page not found".
- `curl -I https://<site>/assets/missing.js`: 404, not 200 HTML.
- `curl -I https://<site>/.htaccess`: 403 or 404 (Apache blocks it by default).
- Browser console: no failed `/assets/*` requests.

## 5. Content-Security-Policy (suggestion, not enabled)

No CSP is shipped, because a wrong one breaks the site silently. The page loads Font Awesome from `cdnjs`, Google
Fonts, Razorpay checkout, and calls the API origin. A starting point to trial in **report-only** mode first:

```
Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' https://checkout.razorpay.com;
  style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com;
  font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: blob: https://<live-api-url-host> https://via.placeholder.com;
  connect-src 'self' https://<live-api-url-host> https://*.razorpay.com; frame-src https://*.razorpay.com
```

Watch the console for violations through a full checkout before switching it to enforcing.
