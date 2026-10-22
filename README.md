# Wedding invitation

An animated wax-seal invitation for Meadow Lark Davies & Vincent Mancini
(Sat 24 Oct 2026, Palomino Highlands Tiki Bar, Los Santos).

It's one self-contained file, `index.html`: the artwork and map are embedded in it,
so there's no build step and no dependencies. The only external request is Google
Fonts (Pinyon Script and Cormorant Garamond); if that's blocked it falls back to
system serif/cursive fonts.

## Pages
1. Invitation (breaking the wax seal opens the folder)
2. Details & Attire
3. Directions (satellite map)

The RSVP buttons (top and bottom) link to the Discord invite: https://discord.gg/9m9wnVgtFY
To change it, search `index.html` for `discord.gg` (it appears twice).

## Docker / Portainer
Live at **https://wedding.gerdsss.cloud**.

`docker-compose.yml` builds a small PHP + Apache container and publishes it on host port **1484**.
Deploy it as a Portainer stack from this repo, then point your reverse proxy / DNS for
`wedding.gerdsss.cloud` at `host:1484`. If 1484 is taken, change the left-hand number in `docker-compose.yml`.
The invitation (`index.html`) is served at `/`, exactly as before.
(`nginx.conf` is no longer used; the container runs Apache so the guest book can use PHP.)

## Guest book
- Public page for guests: **https://wedding.gerdsss.cloud/guestbook/**
- Owner page (moderate, close the book, download everything as PNG pages): **https://wedding.gerdsss.cloud/guestbook/admin.php**

Guests enter a name and a message. Signatures are stored in the `wedding_data` Docker volume, so they
survive redeploys. Set the owner password in Portainer (Stack > Environment variables), then redeploy:

    GUESTBOOK_ADMIN_PASSWORD = a password you choose          (simplest)
    # or GUESTBOOK_ADMIN_HASH = a bcrypt hash                 (you can reuse the dashboard's APP_PASSWORD_HASH)

Optional: `TRUSTED_IP_HEADER=CF-Connecting-IP` if the proxy sits behind Cloudflare.
Layout: `guestbook/` (page, API, admin, fonts), `includes/` (storage + login code, not web-reachable), `docker/` (Apache + PHP config).

## Hosting (without Docker)
- **GitHub Pages:** push this repo, then Settings > Pages > deploy from the `main` branch, `/ (root)`.
  The invitation will be at `https://<user>.github.io/<repo>/`.
- **Anywhere else:** upload `index.html` to any static host (Netlify, Cloudflare Pages, Apache/nginx...).

The page includes a `noindex` tag so search engines skip it.
