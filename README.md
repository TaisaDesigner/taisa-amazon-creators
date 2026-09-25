# Taisa Amazon Creators

New, lightweight WordPress plugin for Amazon's Creators API, designed as a simpler alternative to AAWP.

It currently focuses on Amazon.es and Amazon.de, with product cards, affiliate links, searches and migration support for several common AAWP shortcodes. It is an independent plugin, not a fork or clone of AAWP.

The project originated from a practical need: after losing access to the older Amazon API setup used by a previous site integration, Taisa needed a simpler way to keep affiliate buttons working and to migrate away from AAWP. The plugin uses the Amazon Creators API when available. If the API is unavailable or does not return product data, it can still generate direct affiliate links from ASINs or search keywords; in that fallback mode it cannot retrieve dynamic product titles or images.

## Main features
- OAuth 2.0 with Credential ID / Credential Secret and credential versions 3.1, 3.2 and 3.3.
- Amazon.es and Amazon.de with independent Partner Tags.
- Primary marketplace button plus optional secondary marketplace flag link.
- All affiliate links use `rel="sponsored nofollow noopener noreferrer"`.
- GetItems by ASIN: official Amazon image URL, title and affiliate detail URL.
- SearchItems for legacy AAWP `bestseller` shortcodes.
- Token caching and item/search caching up to 24 hours.
- Never downloads Amazon product images to WordPress; only caches image URLs.
- Fallback buttons/search links if Creators API is unavailable.
- Optional AAWP shortcode compatibility.
- Legacy AAWP tables rendered directly from their stored WordPress post meta.

## Supported AAWP patterns
- `[aawp box="ASIN"]`
- `[aawp box="ASIN1,ASIN2" grid="2"]`
- `[aawp link="ASIN" title="Texto"]`
- `[aawp bestseller="keywords" items="3" grid="3"]`
- `[aawp table="123"]`
- `[aawp asin="ASIN"]`
- `[amazon ...]`

Attributes such as `price="hide"` and `template="table"` are accepted for compatibility even when they do not change output in this version.

## Own shortcodes
- `[taisa_amazon asin="B0CJDSQYND"]`
- `[taisa_amazon_search keywords="plastificadora" items="3"]`

## Configuration
Settings > Amazon Creators.

The project is related to the guide [Amazon Afiliados: cómo empezar](https://www.taisa-designer.com/amazon-afiliados-blog/), which explains the broader affiliate setup and strategy. This plugin is the technical WordPress component for displaying Amazon products and affiliate links.

Credentials can also be provided in wp-config.php:

```php
define( 'TAC_CREDENTIAL_ID', '...' );
define( 'TAC_CREDENTIAL_SECRET', '...' );
define( 'TAC_CREDENTIAL_VERSION', '3.2' );
```

## Migration strategy
1. Install and activate this plugin with AAWP compatibility disabled.
2. Configure Creators API credentials and ES/DE Partner Tags.
3. Test an ASIN in both marketplaces.
4. If AAWP or another old fallback plugin is active, disable it after verifying this plugin on representative pages.
5. Enable AAWP compatibility in this plugin.
6. Verify representative box, link, bestseller and table shortcodes.
7. Remove AAWP only after the site is verified.

## 0.2.2
- Corrección de la caché negativa: los ASIN no encontrados no se renderizan como productos incompletos y activan correctamente el fallback.

## 0.2.1
- Diagnóstico OAuth separado de la consulta de productos.
- Registro seguro de los últimos 50 eventos OAuth/Creators API (sin secretos ni tokens).
- Muestra endpoint, versión, ID enmascarado, longitudes de credencial, HTTP y errores devueltos por Amazon.
- Botones renderizados con las clases nativas `wp-block-buttons`, `wp-block-button` y `wp-block-button__link` para heredar el estilo Gutenberg del sitio.

