# Taisa Amazon Creators

New, lightweight WordPress plugin for Amazon's Creators API, with product cards, affiliate links, searches and optional compatibility with several common legacy AAWP shortcodes.

It currently focuses on Amazon.es and Amazon.de. This is an independent implementation built from scratch for Amazon Creators API. It is not a fork, clone or derivative distribution of AAWP, does not include AAWP code or assets, and is not affiliated with, endorsed by, sponsored by or maintained by the authors of AAWP. References to AAWP are used only to describe optional interoperability with content already stored in WordPress sites.

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
- Legacy AAWP tables rendered directly from their existing WordPress post meta when that data is still present.

## Independence and legacy compatibility

AAWP compatibility is optional and exists to help site owners keep previously published content working while migrating to this plugin.

The plugin does **not** install AAWP, copy AAWP code, create AAWP's custom content structures or recreate missing AAWP table data. Legacy table support only reads data that is already stored in WordPress, such as `_aawp_table_rows` and `_aawp_table_products`.

This means:

- If AAWP is deactivated but its legacy table posts and metadata remain in the database, `[aawp table="123"]` can still be rendered by this plugin.
- If the site never used AAWP, or those legacy table records have been deleted, there is no legacy table data for the plugin to render.
- Taisa Amazon Creators does not currently provide its own visual table editor or create replacement table records automatically.
- Do not delete legacy AAWP table data until you have verified or migrated the tables you want to keep.

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
7. Remove AAWP only after the site is verified. If you use legacy AAWP tables, keep their stored posts/meta until those tables have been verified or migrated.

## 0.2.3
- Añade contexto visible a los resultados de búsqueda.
- Mejora el formato de los botones y banderas en las tablas legacy para evitar botones verticales y demasiado estrechos.

## 0.2.2
- Corrección de la caché negativa: los ASIN no encontrados no se renderizan como productos incompletos y activan correctamente el fallback.

## 0.2.1
- Diagnóstico OAuth separado de la consulta de productos.
- Registro seguro de los últimos 50 eventos OAuth/Creators API (sin secretos ni tokens).
- Muestra endpoint, versión, ID enmascarado, longitudes de credencial, HTTP y errores devueltos por Amazon.
- Botones renderizados con las clases nativas `wp-block-buttons`, `wp-block-button` y `wp-block-button__link` para heredar el estilo Gutenberg del sitio.

