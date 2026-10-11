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
`docker-compose.yml` builds a tiny nginx container and publishes it on host port **1484**
(the dashboard already uses 1422). Deploy it as a Portainer stack from this repo, then point
your reverse proxy / DNS for `wedding.gerdsss.cloud` at `host:1484`. If 1484 is taken on
your server, change the left-hand number in `docker-compose.yml`.

## Hosting (without Docker)
- **GitHub Pages:** push this repo, then Settings > Pages > deploy from the `main` branch, `/ (root)`.
  The invitation will be at `https://<user>.github.io/<repo>/`.
- **Anywhere else:** upload `index.html` to any static host (Netlify, Cloudflare Pages, Apache/nginx...).

The page includes a `noindex` tag so search engines skip it.
