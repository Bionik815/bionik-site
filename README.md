# bionikwebdesigns.com

Two things live in this repo, deployed to Hostinger (Business web hosting) through hPanel's Git deploy:

| Path | Serves | What it is |
| --- | --- | --- |
| `index.html` | bionikwebdesigns.com | Splash page |
| `go/` | go.bionikwebdesigns.com | **Bionik Links**: dynamic NFC/QR links with scan tracking |

## How Bionik Links works

- Each link has a tag ID, e.g. `007`.
- **NFC tags** get `https://go.bionikwebdesigns.com/007`.
- **QR codes** encode `https://go.bionikwebdesigns.com/007?q`. The `?q` is how scans are counted as QR instead of NFC.
- Every scan is logged (time, NFC/QR, iOS/Android/other, an anonymous daily visitor key) and then redirected with a no-cache 302.
- Destinations are edited in the admin at `go.bionikwebdesigns.com/admin`, so printed tags and codes never need to change.
- Unknown or disabled tags go to the main site, and unknown scans are listed in the admin so typos are easy to spot.
- Bots, link previewers, and `HEAD` requests are not counted. No IP addresses are stored.
- UTM tags (`utm_source=nfc|qr`, `utm_campaign=<client>`, `utm_content=<tag>`) can be switched on per link for store analytics.

Data lives in SQLite at `~/domains/bionikwebdesigns.com/nfc-data/bionik-links.sqlite`, outside the web root and outside Git. The folder is created automatically.

## Requirements

PHP 8.1+ with `pdo_sqlite`, and LiteSpeed or Apache with `.htaccess`. Hostinger Business has both; set PHP to 8.2 or newer in hPanel → Advanced → PHP Configuration.

## First deploy (Hostinger)

1. **Subdomain:** hPanel → Domains → Subdomains → create `go`. Its folder must be `public_html/go`.
2. **Empty public_html:** hPanel's Git deploy needs an empty target folder. In File Manager, delete Hostinger's placeholder files in `public_html` (keep the `go` folder only if it's empty; otherwise delete it too).
3. **Git:** hPanel → Advanced → Git.
   - Repository: `git@github.com:BioniK815/bionik-site.git`
   - Branch: `main`
   - Directory: leave blank (deploys to `public_html`)
   - Copy the SSH key hPanel shows, and add it on GitHub under repo → Settings → Deploy keys (read-only).
   - Click **Create**, then **Deploy**.
4. **Auto-deploy (optional):** in hPanel's Git section, open Auto Deployment, copy the webhook URL, and add it on GitHub under repo → Settings → Webhooks (content type `application/json`, push events).
5. **SSL:** make sure SSL is active for both `bionikwebdesigns.com` and `go.bionikwebdesigns.com` (hPanel → Security → SSL).
6. **Create the admin login immediately:** open `https://go.bionikwebdesigns.com/admin`. The first visit shows a one-time setup form; after an account exists it's disabled.

## Verify

- `https://go.bionikwebdesigns.com/` redirects to the main site.
- Create a test link, open its URL on a phone, and confirm the scan shows up in the admin.
- If redirects work but scans don't appear, turn off LiteSpeed caching for the subdomain in hPanel's Cache Manager.

## Optional overrides

Copy `go/app/config.local.example.php` to `go/app/config.local.php` on the server (it's git-ignored) to change the fallback URL, timezone, public URL, or data folder.

## Local development

```sh
php -S 127.0.0.1:8099 -t go go/index.php
```

Then open http://127.0.0.1:8099/admin. Point `data_dir` at a scratch folder with a `config.local.php` first.

## Backups

Download `nfc-data/bionik-links.sqlite` from File Manager now and then, or include it in Hostinger's backups. The links and all scan history live in that one file.
