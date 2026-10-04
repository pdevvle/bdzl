#!/usr/bin/env python3
"""Faithful mock of the single-product page as this site actually renders it.

Everything structural here was read out of the installed Astra 4.13.11 and
Astra Pro 4.13.9 rather than assumed:

  * div.product carries ast-product-gallery-layout-vertical and
    ast-product-tabs-layout-vertical (astra-settings: single-product-gallery-
    layout = vertical-slider, single-product-tabs-layout = vertical).
  * images 47% / summary 49% (single-product-image-width = 47, and Astra Pro's
    $product_desc_width = 96 - 47).
  * The gallery markup is Astra Pro's templates/single-product-gallery.php,
    which replaces the flexslider thumb nav with #ast-gallery-thumbnails.
    flexslider's own .flex-control-thumbs is ALSO emitted in some paths and
    Astra styles it as a calc(25% - 1em) column, so the mock renders both.
  * sticky-product-image.js wraps gallery+summary in
    .ast-product-img-summary-wrapper.ast-is-sticky-product-image and hard-sets
    wrapper.style.height to summary.scrollHeight IN PIXELS, measured once at
    DOMContentLoaded. The mock reproduces that frozen height.
  * sticky-summary emits .ast-sticky-row{display:flex...} and
    .ast-sticky-row .summary{position:sticky} -- with no top offset.

    python3 mkproduct.py            # current design-system.css
    python3 mkproduct.py --nocustom # Astra only, to see the baseline
"""
import pathlib, sys

custom = "--nocustom" not in sys.argv
css = pathlib.Path("/home/user/bdzl/site/design-system.css").read_text() if custom else ""

# --- CSS copied out of the installed Astra / Astra Pro -----------------------
ASTRA = """
/* woocommerce core-ish layout */
.woocommerce div.product div.images { float: left; width: 47%; }
.woocommerce div.product div.summary { float: right; width: 49%; clear: none; }
.woocommerce div.product::after { content: ""; display: table; clear: both; }

/* Astra Pro: vertical gallery (dynamic.css.php, LTR branch) */
.woocommerce div.product div.images.woocommerce-product-gallery > .flex-viewport {
  margin-left: calc(20% + 10px); margin-bottom: 0; }
#ast-vertical-thumbnail-wrapper { position: relative; overflow: hidden; }
#ast-gallery-thumbnails { position: absolute; width: 20%; margin-top: -5px; transition: .3s; }
.woocommerce-product-gallery-thumbnails__wrapper { position: absolute; }
.ast-woocommerce-product-gallery__image {
  position: relative; display: block; width: inherit; padding-bottom: 100%;
  border-top: 5px solid transparent; border-bottom: 5px solid transparent; }
#ast-vertical-thumbnail-wrapper .ast-woocommerce-product-gallery__image img {
  position: absolute; right: 0; left: 0; bottom: 0; top: 0;
  width: 100%; height: 100%; object-fit: cover; }
/* flexslider thumb nav, as Astra sizes it for the vertical layout */
.woocommerce div.product.ast-product-gallery-layout-vertical div.images .flex-control-thumbs {
  width: calc(25% - 1em); }
.woocommerce div.product.ast-product-gallery-layout-vertical div.images .flex-control-thumbs li {
  width: 100%; }
.flex-control-thumbs { position: absolute; top: 0; left: 0; list-style: none; margin: 0; padding: 0; }

.woocommerce.single-product .related.products { width: 100%; }

/* Astra Pro: gallery prev/next arrows (dynamic.css.php) */
.woocommerce-product-gallery .flex-direction-nav .flex-prev,
.woocommerce-product-gallery .flex-direction-nav .flex-next,
#ast-vertical-navigation-prev,
#ast-vertical-navigation-next {
  position: absolute; width: 30px; height: 30px; padding: 0; color: transparent;
  background-color: #ffffff; border-radius: 100%; font-size: 0;
  box-shadow: 0 0 5px 0 rgb(0 0 0 / 30%); z-index: 1; opacity: .8; }
#ast-vertical-navigation-prev:after,
#ast-vertical-navigation-next:after {
  content: ""; position: absolute; top: 10px; left: 9px; width: 10px; height: 10px;
  border-top: 2px solid #56505f; border-right: 2px solid #56505f;
  transform: rotate(-45deg); }
#ast-vertical-navigation-next { top: 40px; }
#ast-vertical-navigation-next:after { transform: rotate(135deg); top: 6px; }

/* Astra Pro: sticky summary */
.ast-sticky-row { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; }
.ast-sticky-row .summary { position: sticky; }

/* Astra Pro: vertical tabs (assets/css/unminified/style.css) */
@media all and (min-width: 421px) {
  .woocommerce div.product.ast-product-tabs-layout-vertical .woocommerce-product-gallery { margin-bottom: 3em; }
  .woocommerce div.product.ast-product-tabs-layout-vertical .woocommerce-tabs {
    margin-bottom: 3.5em; display: flex; flex-wrap: wrap; }
  .woocommerce div.product.ast-product-tabs-layout-vertical .woocommerce-tabs ul.tabs {
    padding: 0; width: 200px; float: left; margin: 0; border: none; }
  .woocommerce div.product.ast-product-tabs-layout-vertical .woocommerce-tabs .panel {
    border: 1px solid #dddddd; border-width: 0 0 0 1px;
    padding: 0 1.5em 1.5em 1.5em; margin-bottom: 0; width: calc(100% - 200px); }
}
.woocommerce div.product.ast-product-tabs-layout-vertical .woocommerce-tabs ul.tabs li {
  width: 100%; margin: 0; border-bottom: none; border-width: 0 0 1px;
  border-style: solid; border-color: #dddddd; list-style: none; }
.woocommerce div.product.ast-product-tabs-layout-vertical .woocommerce-tabs ul.tabs li a {
  width: 100%; padding: .5em 0 .5em .8em; display: block; }
"""

SHELL = """
* { box-sizing: border-box }
body { margin: 0 }
img { max-width: 100%; display: block }
.ast-container { max-width: 1160px; margin: 0 auto; padding: 0 20px }
.site-header { background: #fff; border-bottom: 1px solid #e8ded1 }
.shdr { max-width: 1160px; margin: 0 auto; padding: 16px 28px; display: flex;
  align-items: center; justify-content: space-between }
.brand { font-family: 'Playfair', serif; font-size: 1.45rem; font-weight: 600; color: #211d26 }
nav a { color: #211d26; text-decoration: none; font-size: .95rem; font-weight: 500; margin-left: 26px }
#content { padding: 44px 0 72px }
.button { display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
  font-family: 'Reddit Sans', sans-serif; font-weight: 600; border-radius: 6px;
  border: 1px solid transparent; padding: 16px 26px }
.quantity { display: inline-block; margin-right: 10px }
.quantity input.qty { width: 72px; padding: 12px 8px; text-align: center }
.single_add_to_cart_button { width: calc(100% - 92px) }
.variations { width: 100%; border-collapse: collapse; margin-bottom: 16px }
.variations tr, .variations th, .variations td { display: block; width: 100%; text-align: left }
.variations th { padding: 0 0 6px }
.variations select { width: 100% }
ul.products { list-style: none; padding: 0; display: grid;
  grid-template-columns: repeat(4, 1fr); gap: 24px }
ul.products li.product { margin: 0 }
.related.products { clear: both; padding-top: 24px }
.site-footer { background: #16121c; color: #9c93a8; font-size: .9rem; padding: 26px 0 }
.sftr { max-width: 1160px; margin: 0 auto; padding: 0 28px }
@media (max-width: 860px) { ul.products { grid-template-columns: repeat(2, 1fr) } }
"""

THUMBS = "".join(
    f'<div data-slide-number="{i}" class="ast-woocommerce-product-gallery__image'
    f'{" flex-active-slide" if i == 0 else ""}"><img src="img/{n}.svg" alt=""></div>'
    for i, n in enumerate(["onyx", "kit", "tog"]))

# The JS freezes this to the summary height measured at DOMContentLoaded --
# before the variation block expands and before the webfonts land.
STALE_HEIGHT = 430

PAGE = f"""<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Playfair:ital,wght@0,400..900;1,400..900&family=Reddit+Sans:wght@300..800&display=swap" rel="stylesheet">
<style>{SHELL}</style>
<style>{ASTRA}</style>
<style>{css}</style>
</head>
<body class="single-product woocommerce woocommerce-page ast-desktop">
<header class="site-header"><div class="shdr"><div class="brand">Bedazzle Book Kits</div><nav><a href="#">Shop</a><a href="#">Custom kits</a><a href="#">About</a><a href="#">Contact</a></nav></div></header>
<div id="content" class="site-content"><div class="ast-container"><div id="primary">

<div id="product-11490" class="product type-product ast-product-gallery-layout-vertical ast-product-tabs-layout-vertical ast-sticky-row">

  <!-- wrapper exactly as sticky-product-image.js builds it, frozen height and all -->
  <div class="ast-product-img-summary-wrapper ast-is-sticky-product-image" style="position:relative;height:{STALE_HEIGHT}px">

    <div class="woocommerce-product-gallery woocommerce-product-gallery--with-images woocommerce-product-gallery--columns-4 images" data-columns="4" style="position:sticky;top:30px">
      <figure class="woocommerce-product-gallery__wrapper">
        <div class="flex-viewport"><img src="img/onyx.svg" alt="Onyx Storm bedazzled cover"></div>
      </figure>
      <div id="ast-gallery-thumbnails">
        <div class="ast-vertical-navigation-wrapper">
          <button id="ast-vertical-navigation-prev"></button>
          <button id="ast-vertical-navigation-next"></button>
        </div>
        <div id="ast-vertical-thumbnail-wrapper">
          <div id="ast-vertical-slider-inner" class="woocommerce-product-gallery-thumbnails__wrapper">{THUMBS}</div>
        </div>
      </div>
    </div>

    <div class="summary entry-summary">
      <div class="product_meta"><span class="posted_in">Category: <a href="#">Book Bedazzle Kits</a></span></div>
      <h1 class="product_title entry-title">Onyx Storm Book Bedazzle Kit (Pre-Order)</h1>
      <p class="price">$37.00</p>
      <div class="woocommerce-product-details__short-description">
        <p>This kit is for the new Onyx Storm hardcover book! If you&rsquo;d like a book included it will automatically be the standard edition. If you&rsquo;d like the deluxe one message me before purchase so I can adjust the price!</p>
      </div>
      <form class="variations_form cart">
        <table class="variations"><tbody>
          <tr><th><label for="book">Book included</label></th>
              <td><select id="book"><option>Choose an option</option><option>Kit only</option></select></td></tr>
          <tr><th><label for="glue">Glue</label></th>
              <td><select id="glue"><option>Choose an option</option><option>Standard</option></select>
              <a class="reset_variations" href="#">Clear</a></td></tr>
        </tbody></table>
        <div class="single_variation_wrap">
          <div class="woocommerce-variation-price"><span class="price">$37.00</span></div>
          <p class="stock in-stock">In stock &middot; ships in 2 to 4 weeks</p>
          <div class="quantity"><input type="number" class="qty" value="1"></div>
          <button type="submit" class="button alt single_add_to_cart_button">Add to cart</button>
        </div>
      </form>
      <div class="ast-shipping-text">Free shipping on orders over $50</div>
    </div>
  </div>

  <div class="woocommerce-tabs">
    <ul class="tabs">
      <li class="active"><a href="#">Description</a></li>
      <li><a href="#">Additional information</a></li>
      <li><a href="#">Reviews (0)</a></li>
    </ul>
    <div class="panel woocommerce-Tabs-panel">
      <h2>Description</h2>
      <p>You will receive the exact gems I used. To follow along just place the gem colors where I did! Small gems are used around words and small details (like the clouds!)</p>
      <p>I&rsquo;m currently on a pre order for these kits so shipping may take two to four weeks to ship!</p>
    </div>
  </div>

  <section class="related products">
    <h2>You may also like</h2>
    <ul class="products">
      <li class="product"><a href="#"><img src="img/fw.svg" alt=""><h2 class="woocommerce-loop-product__title">Fourth Wing Hardcover Kit</h2></a><span class="price">$37.00</span><a href="#" class="button">Add to cart</a></li>
      <li class="product"><a href="#"><img src="img/dcc.svg" alt=""><h2 class="woocommerce-loop-product__title">Dungeon Crawler Carl Kit</h2></a><span class="price">$35.00</span><a href="#" class="button">Add to cart</a></li>
      <li class="product"><a href="#"><img src="img/acotar.svg" alt=""><h2 class="woocommerce-loop-product__title">ACOTAR all five</h2></a><span class="price">$147.00</span><a href="#" class="button">Add to cart</a></li>
      <li class="product"><a href="#"><img src="img/rr.svg" alt=""><h2 class="woocommerce-loop-product__title">Red Rising Series Kits</h2></a><span class="price">$175.00</span><a href="#" class="button">Add to cart</a></li>
    </ul>
  </section>

</div></div></div>
<footer class="site-footer"><div class="sftr">&copy; 2026 Bedazzle Book Kits &middot; Shop &middot; About &middot; Contact</div></footer>
</body></html>
"""

out = pathlib.Path(__file__).with_name("product.html" if custom else "product-base.html")
out.write_text(PAGE)
print("wrote", out.name)
