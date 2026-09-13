#!/usr/bin/env python3
"""Mock the single-product page Astra + WooCommerce render, with the real
design-system.css applied, so the velvet panel can be screenshotted locally."""
import pathlib

css = pathlib.Path("/home/user/bdzl/site/design-system.css").read_text()

PAGE = """<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Playfair:ital,wght@0,400..900;1,400..900&family=Reddit+Sans:wght@300..800&display=swap" rel="stylesheet">
<style>
/* --- minimal stand-in for Astra + WooCommerce structural CSS --- */
*{box-sizing:border-box}
body{margin:0}
img{max-width:100%%;display:block}
.ast-container{max-width:1160px;margin:0 auto;padding:0 20px}
.site-header{background:#fff;border-bottom:1px solid #e8ded1}
.shdr{max-width:1160px;margin:0 auto;padding:16px 28px;display:flex;align-items:center;justify-content:space-between}
.brand{font-family:'Playfair',serif;font-size:1.45rem;font-weight:600;color:#211d26}
nav a{color:#211d26;text-decoration:none;font-size:.95rem;font-weight:500;margin-left:26px}
#content{padding:44px 0 72px}
.woocommerce-breadcrumb{font-size:.86rem;color:#7a7185;margin-bottom:26px}
.woocommerce-breadcrumb a{color:#7a7185;text-decoration:none}
div.product{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:48px;align-items:start}
.woocommerce-product-gallery{min-width:0}
.summary{min-width:0}
.product_title{margin:0 0 10px}
p.price{margin:0}
form.cart{margin-top:22px}
.variations{width:100%%;border-collapse:collapse;margin-bottom:16px}
.variations th{text-align:left;padding:0 0 6px;width:100%%;display:block}
.variations td{display:block;width:100%%}
.variations tr{display:block}
.single_variation_wrap{display:block}
.woocommerce-variation-price{margin-bottom:16px}
.quantity{display:inline-block;margin-right:10px}
.quantity input.qty{width:72px;padding:12px 8px;text-align:center}
.button{display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
  font-family:'Reddit Sans',sans-serif;font-weight:600;border-radius:6px;border:1px solid transparent;padding:16px 26px}
.single_add_to_cart_button{width:calc(100%% - 92px)}
.product_meta{display:block}
.woocommerce-tabs{grid-column:1/-1;margin-top:56px}
.woocommerce-tabs ul.tabs{list-style:none;display:flex}
.related.products{grid-column:1/-1;margin-top:24px}
ul.products{list-style:none;padding:0;display:grid;grid-template-columns:repeat(4,1fr);gap:24px}
ul.products li.product{margin:0}
.site-footer{background:#16121c;color:#9c93a8;font-size:.9rem;padding:26px 0}
.sftr{max-width:1160px;margin:0 auto;padding:0 28px}
@media(max-width:860px){div.product{grid-template-columns:1fr;gap:28px}ul.products{grid-template-columns:repeat(2,1fr)}}
</style>
<style>
%s
</style>
</head>
<body class="single-product woocommerce woocommerce-page">
<header class="site-header"><div class="shdr"><div class="brand">Bedazzle Book Kits</div><nav><a href="#">Shop</a><a href="#">Custom kits</a><a href="#">About</a><a href="#">Contact</a></nav></div></header>
<div id="content" class="site-content"><div class="ast-container"><div id="primary">

<nav class="woocommerce-breadcrumb"><a href="#">Home</a> / <a href="#">Shop</a> / Onyx Storm Book Bedazzle Kit</nav>

<div id="product-11490" class="product type-product">

  <div class="woocommerce-product-gallery">
    <img src="img/onyx.svg" alt="Onyx Storm bedazzled cover">
    <ol class="flex-control-thumbs" style="list-style:none;padding:0">
      <li><img class="flex-active" src="img/onyx.svg" alt=""></li>
      <li><img src="img/kit.svg" alt=""></li>
      <li><img src="img/tog.svg" alt=""></li>
    </ol>
  </div>

  <div class="summary entry-summary">
    <h1 class="product_title entry-title">Onyx Storm Book Bedazzle Kit (Pre-Order)</h1>
    <p class="price">$37.00</p>
    <div class="woocommerce-product-details__short-description">
      <p>This kit is for the new Onyx Storm hardcover book! If you&rsquo;d like a book included it will automatically be the standard edition. If you&rsquo;d like the deluxe one message me before purchase so I can adjust the price!</p>
    </div>

    <form class="variations_form cart">
      <table class="variations">
        <tbody>
          <tr><th><label for="book">Book included</label></th>
              <td><select id="book"><option>Choose an option</option><option>Kit only</option><option>Kit + standard hardcover</option></select></td></tr>
          <tr><th><label for="glue">Glue</label></th>
              <td><select id="glue"><option>Choose an option</option><option>Standard</option><option>Upgrade (+$7)</option></select>
              <a class="reset_variations" href="#">Clear</a></td></tr>
        </tbody>
      </table>
      <div class="single_variation_wrap">
        <div class="woocommerce-variation-price"><span class="price">$37.00</span></div>
        <p class="stock in-stock">In stock &middot; ships in 2 to 4 weeks</p>
        <div class="quantity"><input type="number" class="qty" value="1"></div>
        <button type="submit" class="button alt single_add_to_cart_button">Add to cart</button>
      </div>
    </form>

    <div class="product_meta">
      <span class="sku_wrapper">SKU: <span class="sku">etsy-1851982718</span></span><br>
      <span class="posted_in">Category: <a href="#">Book Bedazzle Kits</a></span>
    </div>
  </div>

  <div class="woocommerce-tabs">
    <ul class="tabs">
      <li class="active"><a href="#">Description</a></li>
      <li><a href="#">What&rsquo;s in the kit</a></li>
      <li><a href="#">Shipping</a></li>
    </ul>
    <div class="panel">
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

</div>
</div></div></div>
<footer class="site-footer"><div class="sftr">Copyright &copy; 2026 Bedazzle Book Kits</div></footer>
</body></html>
""" % css

pathlib.Path(__file__).with_name("product.html").write_text(PAGE)
print("product.html written")
