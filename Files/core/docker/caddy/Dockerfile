# Custom Caddy dengan modul rate_limit + DNS-01 Cloudflare
FROM caddy:2-builder AS builder
RUN xcaddy build \
	--with github.com/mholt/caddy-ratelimit \
	--with github.com/caddy-dns/cloudflare

FROM caddy:2
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
