# WordPress.org plugin-page assets

These files are NOT shipped inside the plugin ZIP. They live in a separate `/assets/` directory at the wordpress.org SVN root and are used by wordpress.org to render the plugin's public listing page.

This folder exists to:

1. Document exactly what files you need to produce.
2. Give you a place to keep them under git version control alongside the plugin source.
3. Stay out of the plugin ZIP — `wporg-assets/` is excluded by [`.distignore`](../.distignore).

When you're ready to publish, copy the files from this folder into the WP.org SVN `/assets/` directory and `svn ci` (see [`docs/WORDPRESS_ORG_SUBMISSION.md`](../docs/WORDPRESS_ORG_SUBMISSION.md), section 5).

---

## Required files

| File | Purpose | Spec |
|---|---|---|
| `banner-1544x500.png` | Large banner shown at the top of the plugin's wordpress.org page on desktop. | 1544 × 500 pixels exactly. PNG or JPG. Keep under 200 KB. Avoid pure-text designs — wordpress.org renders the plugin name in a separate text overlay, so a banner that's mostly text reads as a duplicate. Show what the plugin DOES (a stylized checkout, payment-method icons, the XPay logo) rather than just the name. |
| `banner-772x250.png` | Smaller banner for low-resolution displays and the mobile site. | 772 × 250 pixels exactly. Same content/style as the large banner, just resized. |
| `icon-256x256.png` | Plugin icon shown in WP Admin → Plugins → Add New search results, and in the wordpress.org plugin search. | 256 × 256 pixels exactly. PNG with transparent background OR a solid square with the XPay brand color. Should be legible at 64×64 and 32×32 sizes too — avoid fine detail. |
| `icon-128x128.png` | Smaller icon for high-DPI fallback. | 128 × 128 pixels exactly. Same content as 256×256 but resized. |
| `screenshot-1.png` | First screenshot — matches `1.` in `readme.txt` `== Screenshots ==`. | Currently set to: "Checkout payment-method picker (classic checkout)." Take from a real merchant store running the plugin. |
| `screenshot-2.png` | Second screenshot. | "Embedded payment modal with 3D Secure card flow." |
| `screenshot-3.png` | Third screenshot. | "Diagnostic logger admin page (Tools → XPay Logger)." |
| `screenshot-4.png` | Fourth screenshot. | "Gateway settings (WooCommerce → Settings → Payments → Xpay)." |

### Screenshot specs

- **Format:** PNG (preferred) or JPG.
- **Dimensions:** Any reasonable size — typical guidance is 1200–1600 pixels wide for the long edge. wordpress.org auto-scales for the plugin page.
- **Aspect ratio:** Match the actual UI you're capturing (don't force a particular shape).
- **Quality:** Crisp, no compression artifacts. Take at the highest available retina resolution then save as PNG.
- **Content:** Real working data, not lorem-ipsum / "test test test". For card numbers in screenshot-2, use the staging test card `5123450000000008` so it's clearly not real.
- **Caption sync:** The captions in `readme.txt` `== Screenshots ==` are ordered to match the file numbers (`screenshot-1.png` ↔ caption #1). If you reorder the captions, rename the files to match.

### File naming

WordPress.org uses **strict** filename matching. The names above are the canonical ones — don't add prefixes / suffixes. If you upload `screenshot-1@2x.png` thinking it's a retina version, wordpress.org will simply ignore it.

If you have more or fewer screenshots, adjust both:
- The number of `screenshot-N.png` files in `wporg-assets/`
- The number of `1.`, `2.`, … entries in `readme.txt` under `== Screenshots ==`

---

## How to verify a banner / icon before uploading

Drop them in this folder, then visually inspect:

```bash
open wporg-assets/banner-1544x500.png    # macOS
xdg-open wporg-assets/banner-1544x500.png # Linux
```

For the icon, view at multiple sizes to confirm it's still readable when scaled:

```bash
# Quick check at 64x64 — what shows up next to the plugin name in WP Admin
sips -Z 64 wporg-assets/icon-256x256.png --out /tmp/icon-64.png && open /tmp/icon-64.png
```

---

## Don't commit binary art if you don't want to

These files are ~50–500 KB each. Some teams version-control them, others don't.

If you'd rather NOT commit binaries to the git repo:

1. Add this line to `.gitignore`:

   ```
   wporg-assets/*.png
   wporg-assets/*.jpg
   wporg-assets/*.svg
   ```

2. Keep this `wporg-assets/README.md` file tracked (so the spec stays in the repo).
3. Stash the actual image files locally — anywhere convenient — and `cp` them into `wporg-assets/` (or directly to your SVN `/assets/`) when publishing.

---

## When you're publishing

See [`docs/WORDPRESS_ORG_SUBMISSION.md`](../docs/WORDPRESS_ORG_SUBMISSION.md) section 5 for the SVN upload commands. In short:

```bash
cd ~/wporg/xpay-for-woocommerce-svn
cp /path/to/this-repo/wporg-assets/banner-1544x500.png assets/
cp /path/to/this-repo/wporg-assets/banner-772x250.png  assets/
cp /path/to/this-repo/wporg-assets/icon-256x256.png    assets/
cp /path/to/this-repo/wporg-assets/icon-128x128.png    assets/
cp /path/to/this-repo/wporg-assets/screenshot-*.png    assets/

svn add assets/*
svn ci -m "Add plugin banner, icon, and screenshots"
```

Within ~30 minutes the assets show up on the public plugin page.
