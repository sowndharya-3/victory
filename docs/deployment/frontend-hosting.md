# Frontend hosting (static SPA)

The frontend is a static Vite build (`dist/`) using React Router `BrowserRouter`. Deep links such as
`/admin/orders`, `/my-books`, `/read/2`, `/books/2`, `/checkout/2`, `/orders/5/result`, `/account` and `/track`
have no file on disk, so the host must answer them with `index.html` (an "SPA fallback"). Without it, a refresh or a
shared link on any page but `/` is a server 404. Hosting is a separate final stage (U7); rules for each likely host ship in the build.

## 1. Build

`VITE_API_URL` is read at **build** time and baked into the bundle. It must be set for the `build` command itself:

```bash
npm ci
VITE_API_URL=https://<live-api-url> npm run build      # bash
# PowerShell: $env:VITE_API_URL="https://<live-api-url>"; npm run build
```

No trailing slash. The production build **fails** with an error if `VITE_API_URL` is unset, invalid, or points at
localhost/127.0.0.1 (`vite.apiUrlCheck.js`). The only escape hatch is `VITE_ALLOW_LOCAL_API=1`, for local
verification builds that must never be uploaded. Extra check: `grep -l localhost:8080 dist/assets/*.js` must print
nothing. The same build step writes the API origin into the CSP of `dist/_headers` and `dist/.htaccess` (the
placeholder `API_ORIGIN_PLACEHOLDER` never ships). Base path is `/`: the site must be served from a
domain root (or sub-domain), not a sub-folder. A sub-folder needs `base` in `vite.config.js` plus matching rules.

Then upload the **contents** of `dist/` (including the dotfile `.htaccess`) to the web root.

On the API, `FRONTEND_ORIGIN` must equal the site origin exactly: scheme + host (+ port), no path, no trailing
slash, e.g. `https://www.example.com`. `https://example.com` and `https://www.example.com` are different origins:
pick one, redirect the other to it, and put that one in `FRONTEND_ORIGIN`, or every API call fails CORS.

## 2. Per host

**Apache / shared hosting (Hostinger, cPanel)**: `dist/.htaccess` does it. It serves real files and directories as
is, returns a real 404 for missing `/assets/*` bundles, and rewrites everything else to `index.html`. It also sets
`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: DENY`, `Permissions-Policy`, the
`Content-Security-Policy-Report-Only` header (section 5), `no-cache` on `index.html` and a one-year immutable
cache on hashed bundles. A commented HSTS line is included; enable it only once HTTPS works on the host. Every block is wrapped in `<IfModule>`. The host must allow `.htaccess` overrides
(`AllowOverride FileInfo`, the default on shared hosting). Some file managers hide dotfiles: confirm `.htaccess` was
uploaded.

**Netlify, Cloudflare Pages**: `dist/_redirects` (`/* /index.html 200`) does it. Static files take priority over the
rule, so assets are unaffected. `dist/_headers` ships with the same headers as `.htaccess` (including the CSP in
Report-Only mode, with the API origin filled in at build time).

**nginx**:

```nginx
root /var/www/vtee/dist;
location / {
    try_files $uri $uri/ /index.html;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    # also add X-Frame-Options DENY, Permissions-Policy and the Report-Only CSP: copy the values from dist/_headers
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

(nginx drops parent `add_header` lines in any location that sets its own, hence the repeats; the headers from
`dist/_headers` must be repeated in each location too. The nginx and Vercel/S3 rules are not shipped as files and were
not tested on a real host.)

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

## 5. Content-Security-Policy (Report-Only, shipped)

The build ships `Content-Security-Policy-Report-Only` in `dist/_headers` (Netlify, Cloudflare Pages) and
`dist/.htaccess` (Apache). Report-Only means violations are logged in the browser console and **nothing is blocked**.
It allows the site itself, Razorpay Checkout (`checkout.razorpay.com`, `cdn.razorpay.com`, `*.razorpay.com`), cdnjs and
Google Fonts, and the API origin (for `connect-src`, `img-src` and the reader's index iframe). `frame-ancestors 'none'`
and `object-src 'none'` are set. The policy text lives in `public/_headers` and `public/.htaccess`; keep the two in
sync. It is not shipped for nginx or other hosts: copy it from `dist/_headers`.

Rollout: deploy as is, complete a full checkout (test mode), reader session and admin session with the console open,
watch for violations for about a week of real traffic, fix any, then rename the header to
`Content-Security-Policy` to enforce it. A CSP cannot be set with a `<meta>` tag in Report-Only mode, so it must be an
HTTP header. Do not enable HSTS before the exact host serves HTTPS correctly; start at `max-age=300` and raise it.

## Verification status of the finished build

Browser E2E ran at 1280, 820 and 390 px on the fake gateway (header Track Order link, mobile menu and admin tables checked at 390 and 820 px). The real Razorpay popup is NOT TESTED. Evidence is kept outside git by the owner and is not linked here. `npm test`: 192 tests in 34 files. The host rules above have not been run on a real host.
