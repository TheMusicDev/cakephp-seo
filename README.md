# TheMusicDev/Seo

> **Status: scaffold.** The plugin loads and does nothing yet. What it will do,
> what we have agreed and what is still open is tracked in
> [`docs/seo-plugin-design.md`](docs/seo-plugin-design.md) — read that first.
> Build order and feature slices: [`docs/delivery-plan.md`](docs/delivery-plan.md).

All-in-one SEO for TheMusicDev CakePHP sites: page meta tags, structured data
(JSON-LD), XML sitemap, robots.txt and redirects. Domain plugins (Recruiting,
a future blog…) get SEO without knowing this plugin exists; the host app wires
them together through config.

## Install

1. composer path repo + `require themusicdev/cakephp-seo ^0.1`
2. `config/plugins.php`: `'TheMusicDev/Seo' => []`

## Configure (host `config/app.php`)

```php
'Seo' => ['subjects' => []],   // which tables are public pages — design doc, A3
```

Host values win over `config/app_default.php`.

## Gotchas

None yet.
