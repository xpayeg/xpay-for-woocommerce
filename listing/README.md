# WordPress.org listing sources

Vector source for the banners in `plugins/woocommerce/.wordpress-org/`, which the
WordPress.org deploy uploads to the SVN `assets/` directory. Only the PNGs are published.

Render a changed source on macOS with:

```bash
sips -s format png banner.svg --out /tmp/banner.png
sips -z 500 1544 /tmp/banner.png --out ../.wordpress-org/banner-1544x500.png
sips -z 250 772 /tmp/banner.png --out ../.wordpress-org/banner-772x250.png
```

The icons render from `brand/icon.svg`, the canonical source derived from the official
vector in `brand/official/`. See `brand/README.md` for the two commands. Do not copy an
icon from another plugin: `AGENTS.md` makes `brand/` the only source of XPay artwork.
`bin/check-brand-assets.sh` enforces the part it can, that each mark a plugin ships is
byte-identical to `brand/`; the images here are built from `brand/`, not checked by it.

The brand marks are the same files the plugin ships in `assets/images/`. The design
matches the published Odoo App Store banner so both listings look like one product.
