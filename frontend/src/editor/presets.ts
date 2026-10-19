// Building blocks of the Add panel. Markup uses the small "sve kit" classes (added to the site's CSS the first
// time a block is used) which read the design tokens (--color-primary, --radius…), so everything inserted matches
// the site's design system and can be restyled per element afterwards.

export const KIT_MARK = '/* sve-kit v1 */';

export const KIT_CSS = `${KIT_MARK}
:root{--sve-primary:var(--color-primary,#4f46e5);--sve-accent:var(--color-accent,#14b8a6);--sve-text:var(--color-text,#0f172a);--sve-muted:var(--color-muted,#64748b);--sve-surface:var(--color-surface,#ffffff);--sve-soft:var(--color-soft,#f1f5f9);--sve-border:var(--color-border,#e2e8f0);--sve-radius:var(--radius,14px);--sve-shadow:var(--shadow,0 10px 30px rgb(15 23 42/.08));--sve-container:var(--container,1200px)}
.sve-section{padding-block:clamp(48px,8vw,112px);padding-inline:20px}
.sve-container{width:100%;max-width:var(--sve-container);margin-inline:auto}
.sve-narrow{max-width:760px;margin-inline:auto}
.sve-stack{display:flex;flex-direction:column;gap:16px}
.sve-row{display:flex;flex-wrap:wrap;gap:16px;align-items:center}
.sve-grid{display:grid;gap:24px;grid-template-columns:repeat(var(--cols,3),minmax(0,1fr))}
@media (max-width:1023px){.sve-grid{grid-template-columns:repeat(min(var(--cols,3),2),minmax(0,1fr))}}
@media (max-width:767px){.sve-grid{grid-template-columns:1fr}.sve-row{flex-direction:column;align-items:stretch}}
.sve-center{text-align:center;align-items:center;justify-content:center}
.sve-eyebrow{display:inline-block;font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sve-primary)}
.sve-h1{font-size:clamp(2.2rem,1.2rem + 4vw,4.2rem);line-height:1.08;font-weight:800;letter-spacing:-.02em;margin:0;color:var(--sve-text);font-family:var(--sve-font-heading,inherit)}
.sve-h2{font-size:clamp(1.7rem,1.1rem + 2.4vw,2.8rem);line-height:1.15;font-weight:750;letter-spacing:-.01em;margin:0;color:var(--sve-text);font-family:var(--sve-font-heading,inherit)}
.sve-h3{font-size:1.25rem;line-height:1.3;font-weight:700;margin:0;color:var(--sve-text)}
.sve-lead{font-size:clamp(1.05rem,1rem + .4vw,1.3rem);line-height:1.65;color:var(--sve-muted);margin:0}
.sve-text{line-height:1.7;color:var(--sve-muted);margin:0}
.sve-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:.85em 1.5em;border-radius:999px;background:var(--sve-primary);color:#fff;font-weight:650;text-decoration:none;border:2px solid transparent;transition:transform .2s ease,box-shadow .2s ease,background .2s ease;cursor:pointer;line-height:1.2}
.sve-btn:hover{transform:translateY(-2px);box-shadow:0 10px 24px color-mix(in srgb,var(--sve-primary) 35%,transparent)}
.sve-btn--outline{background:transparent;color:var(--sve-primary);border-color:currentColor}
.sve-btn--ghost{background:var(--sve-soft);color:var(--sve-text)}
.sve-card{background:var(--sve-surface);border:1px solid var(--sve-border);border-radius:var(--sve-radius);padding:28px;box-shadow:var(--sve-shadow);display:flex;flex-direction:column;gap:12px}
.sve-card img{border-radius:calc(var(--sve-radius) - 6px);width:100%;height:auto;object-fit:cover}
.sve-media{border-radius:var(--sve-radius);overflow:hidden;width:100%;height:auto;display:block;object-fit:cover}
.sve-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;background:color-mix(in srgb,var(--sve-primary) 12%,transparent);color:var(--sve-primary);font-size:.8rem;font-weight:650}
.sve-icon{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--sve-primary) 12%,transparent);color:var(--sve-primary)}
.sve-soft{background:var(--sve-soft)}
.sve-dark{background:#0b1020;color:#e2e8f0}.sve-dark .sve-h1,.sve-dark .sve-h2,.sve-dark .sve-h3{color:#fff}.sve-dark .sve-lead,.sve-dark .sve-text{color:#94a3b8}
.sve-split{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(24px,5vw,72px);align-items:center}
@media (max-width:900px){.sve-split{grid-template-columns:1fr}}
.sve-stat b{display:block;font-size:clamp(2rem,1.4rem + 2vw,3rem);font-weight:800;color:var(--sve-text)}
.sve-price{font-size:2.6rem;font-weight:800;color:var(--sve-text)}
.sve-list{list-style:none;padding:0;margin:0;display:grid;gap:10px}.sve-list li::before{content:"✓";color:var(--sve-accent);font-weight:800;margin-inline-end:10px}
.sve-faq details{border-bottom:1px solid var(--sve-border);padding:18px 0}.sve-faq summary{cursor:pointer;font-weight:650;font-size:1.05rem;list-style:none;display:flex;justify-content:space-between;gap:16px}.sve-faq summary::after{content:"+";color:var(--sve-primary);font-size:1.3rem;line-height:1}.sve-faq details[open] summary::after{content:"–"}.sve-faq details p{margin:12px 0 0;color:var(--sve-muted);line-height:1.7}
.sve-input,.sve-form input,.sve-form textarea,.sve-form select{width:100%;padding:.8em 1em;border-radius:calc(var(--sve-radius) - 4px);border:1px solid var(--sve-border);background:var(--sve-surface);font:inherit;color:inherit}
.sve-form label{display:grid;gap:6px;font-weight:600;font-size:.9rem}
.sve-form-message{margin:8px 0 0;font-weight:600}.sve-form-message[data-state=error]{color:#dc2626}.sve-form-message[data-state=success]{color:#16a34a}
.sve-header{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--sve-surface) 85%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--sve-border)}
.sve-header .sve-container{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:14px 20px}
.sve-nav ul,.sve-menu{list-style:none;margin:0;padding:0;display:flex;gap:24px}.sve-nav a,.sve-menu a{text-decoration:none;color:var(--sve-text);font-weight:550}
@media (max-width:767px){.sve-nav{display:none}}
.sve-footer{background:#0b1020;color:#94a3b8;padding:64px 20px 32px}.sve-footer a{color:#cbd5e1;text-decoration:none}.sve-footer h4{color:#fff;margin:0 0 12px}
.sve-logo-strip{display:flex;flex-wrap:wrap;gap:40px;justify-content:center;align-items:center;opacity:.7;filter:grayscale(1)}
.sve-quote{font-size:1.15rem;line-height:1.7;color:var(--sve-text);margin:0}
.sve-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover}
.sve-breadcrumbs ol{list-style:none;display:flex;flex-wrap:wrap;gap:8px;padding:0;margin:0;font-size:.9rem;color:var(--sve-muted)}.sve-breadcrumbs li+li::before{content:"/";margin-inline-end:8px;opacity:.5}
.sve-pagination ul{list-style:none;display:flex;gap:6px;padding:0;justify-content:center}.sve-pagination a,.sve-pagination span{display:grid;place-items:center;min-width:40px;height:40px;border-radius:10px;border:1px solid var(--sve-border);text-decoration:none}.sve-pagination .current{background:var(--sve-primary);color:#fff;border-color:transparent}
.sve-alert{border:1px solid var(--sve-border);border-radius:var(--sve-radius);padding:16px 18px;display:grid;gap:4px;background:var(--sve-surface)}
`;

export type Preset = { id: string; name: string; group: 'elements' | 'dynamic' | 'sections' | 'kit'; hint?: string; html: string; needsKit?: boolean; woo?: boolean };

const img = (w: number, h: number, q = 'travel') => `https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=${w}&h=${h}&fit=crop&q=80&auto=format&${q}`;

export const PRESETS: Preset[] = [
  /* ---------------- elements ---------------- */
  { id: 'section', name: 'Section', group: 'elements', hint: 'Full-width band with a container', needsKit: true, html: '<section class="sve-section"><div class="sve-container sve-stack"><h2 class="sve-h2">Section title</h2><p class="sve-lead">Write something helpful here.</p></div></section>' },
  { id: 'container', name: 'Container', group: 'elements', needsKit: true, html: '<div class="sve-container sve-stack"><p class="sve-text">Content</p></div>' },
  { id: 'cols2', name: '2 columns', group: 'elements', needsKit: true, html: '<div class="sve-grid" style="--cols:2"><div class="sve-stack"><h3 class="sve-h3">Column one</h3><p class="sve-text">Text</p></div><div class="sve-stack"><h3 class="sve-h3">Column two</h3><p class="sve-text">Text</p></div></div>' },
  { id: 'cols3', name: '3 columns', group: 'elements', needsKit: true, html: '<div class="sve-grid" style="--cols:3"><div class="sve-card"><h3 class="sve-h3">One</h3><p class="sve-text">Text</p></div><div class="sve-card"><h3 class="sve-h3">Two</h3><p class="sve-text">Text</p></div><div class="sve-card"><h3 class="sve-h3">Three</h3><p class="sve-text">Text</p></div></div>' },
  { id: 'cols4', name: '4 columns', group: 'elements', needsKit: true, html: '<div class="sve-grid" style="--cols:4"><div class="sve-card">One</div><div class="sve-card">Two</div><div class="sve-card">Three</div><div class="sve-card">Four</div></div>' },
  { id: 'h1', name: 'Heading 1', group: 'elements', needsKit: true, html: '<h1 class="sve-h1">A big, clear headline</h1>' },
  { id: 'h2', name: 'Heading 2', group: 'elements', needsKit: true, html: '<h2 class="sve-h2">Section heading</h2>' },
  { id: 'h3', name: 'Heading 3', group: 'elements', needsKit: true, html: '<h3 class="sve-h3">Small heading</h3>' },
  { id: 'p', name: 'Paragraph', group: 'elements', needsKit: true, html: '<p class="sve-text">Write your text here. Double-click to edit it right on the page.</p>' },
  { id: 'btn', name: 'Button', group: 'elements', needsKit: true, html: '<a class="sve-btn" href="#">Get started</a>' },
  { id: 'btn2', name: 'Button pair', group: 'elements', needsKit: true, html: '<div class="sve-row"><a class="sve-btn" href="#">Book now</a><a class="sve-btn sve-btn--outline" href="#">Learn more</a></div>' },
  { id: 'img', name: 'Image', group: 'elements', needsKit: true, html: `<img class="sve-media" src="${img(1200, 800)}" alt="" loading="lazy">` },
  { id: 'video', name: 'Video (YouTube)', group: 'elements', html: '<div style="aspect-ratio:16/9"><iframe src="https://www.youtube-nocookie.com/embed/ScMzIvxBSi4" title="Video" style="width:100%;height:100%;border:0;border-radius:14px" loading="lazy" allowfullscreen></iframe></div>' },
  { id: 'map', name: 'Map', group: 'elements', html: '<iframe src="https://www.openstreetmap.org/export/embed.html?bbox=98.25%2C7.75%2C98.45%2C7.95&layer=mapnik" title="Map" style="width:100%;height:360px;border:0;border-radius:14px" loading="lazy"></iframe>' },
  { id: 'list', name: 'Check list', group: 'elements', needsKit: true, html: '<ul class="sve-list"><li>First benefit</li><li>Second benefit</li><li>Third benefit</li></ul>' },
  { id: 'quote', name: 'Quote', group: 'elements', needsKit: true, html: '<blockquote class="sve-card"><p class="sve-quote">“The best trip we have ever had.”</p><cite class="sve-text">— Sara, Tehran</cite></blockquote>' },
  { id: 'badge', name: 'Badge', group: 'elements', needsKit: true, html: '<span class="sve-badge">New</span>' },
  { id: 'card', name: 'Card', group: 'elements', needsKit: true, html: `<article class="sve-card"><img src="${img(800, 500)}" alt="" loading="lazy"><h3 class="sve-h3">Card title</h3><p class="sve-text">Short description.</p><a class="sve-btn sve-btn--ghost" href="#">Details</a></article>` },
  { id: 'divider', name: 'Divider', group: 'elements', html: '<hr style="border:0;border-top:1px solid #e2e8f0;margin:32px 0">' },
  { id: 'spacer', name: 'Spacer', group: 'elements', html: '<div style="height:64px" aria-hidden="true"></div>' },
  { id: 'accordion', name: 'Accordion / FAQ', group: 'elements', needsKit: true, html: '<div class="sve-faq"><details><summary>Question one?</summary><p>Answer one.</p></details><details><summary>Question two?</summary><p>Answer two.</p></details></div>' },
  { id: 'form', name: 'Contact form', group: 'elements', needsKit: true, hint: 'Submissions are stored and e-mailed to you', html: '<form class="sve-form sve-stack" data-sve-form="contact" data-sve-success="Thanks! We will reply soon."><label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label><label>Message<textarea name="message" rows="4" required></textarea></label><button class="sve-btn" type="submit">Send</button></form>' },
  { id: 'table', name: 'Table', group: 'elements', html: '<table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:start;padding:10px;border-bottom:2px solid #e2e8f0">Item</th><th style="text-align:start;padding:10px;border-bottom:2px solid #e2e8f0">Price</th></tr></thead><tbody><tr><td style="padding:10px;border-bottom:1px solid #e2e8f0">Tour</td><td style="padding:10px;border-bottom:1px solid #e2e8f0">$120</td></tr></tbody></table>' },
  { id: 'html', name: 'Custom HTML', group: 'elements', hint: 'Any markup', html: '<div><!-- your HTML --></div>' },

  /* ---------------- dynamic ---------------- */
  { id: 'd-title', name: 'Post title', group: 'dynamic', needsKit: true, html: '<h1 class="sve-h1">{{ post.title }}</h1>' },
  { id: 'd-meta', name: 'Post meta', group: 'dynamic', needsKit: true, html: '<p class="sve-text">{{ post.date }} · {{ post.author.name }} · {{ post.reading_time }} min</p>' },
  { id: 'd-image', name: 'Featured image', group: 'dynamic', needsKit: true, html: '{{#if post.has_image}}<img class="sve-media" src="{{ post.image.large }}" alt="{{ post.image.alt }}">{{/if}}' },
  { id: 'd-content', name: 'Post content', group: 'dynamic', html: '<div class="sve-narrow"><sve-content></sve-content></div>' },
  { id: 'd-posts', name: 'Posts grid', group: 'dynamic', needsKit: true, hint: 'Latest posts, live', html: '<div class="sve-grid" style="--cols:3"><sve-loop query=\'{"post_type":"post","posts_per_page":6}\' as="item"><article class="sve-card"><a href="{{ item.url }}"><img src="{{ item.image.medium_large }}" alt="{{ item.title }}" loading="lazy"></a><span class="sve-badge">{{ item.category.name }}</span><h3 class="sve-h3"><a href="{{ item.url }}" style="color:inherit;text-decoration:none">{{ item.title }}</a></h3><p class="sve-text">{{ item.excerpt | words:20 }}</p></article><sve-empty><p class="sve-text">No posts yet.</p></sve-empty></sve-loop></div>' },
  { id: 'd-products', name: 'Products grid', group: 'dynamic', woo: true, needsKit: true, hint: 'WooCommerce products with price & cart', html: '<div class="sve-grid" style="--cols:4"><sve-loop query=\'{"post_type":"product","posts_per_page":8,"orderby":"popularity"}\' as="item"><article class="sve-card"><a href="{{ item.url }}"><img src="{{ item.image.medium_large }}" alt="{{ item.title }}" loading="lazy"></a>{{#if item.on_sale}}<span class="sve-badge">-{{ item.discount }}%</span>{{/if}}<h3 class="sve-h3">{{ item.title }}</h3><div>{{{ item.price_html }}}</div>{{{ item.add_to_cart }}}</article></sve-loop></div>' },
  { id: 'd-product', name: 'Product summary', group: 'dynamic', woo: true, needsKit: true, hint: 'For single product templates', html: '<div class="sve-split"><img class="sve-media" src="{{ product.image.large }}" alt="{{ post.title }}"><div class="sve-stack"><h1 class="sve-h1">{{ post.title }}</h1><div class="sve-lead">{{{ product.price_html }}}</div><div class="sve-text">{{{ product.short_description }}}</div>{{{ product.add_to_cart }}}<p class="sve-text">SKU: {{ product.sku | default:"—" }} · {{ product.categories | join:", " }}</p></div></div>' },
  { id: 'd-terms', name: 'Categories', group: 'dynamic', needsKit: true, html: '<div class="sve-row"><sve-loop source="terms" query=\'{"taxonomy":"category","number":12}\' as="cat"><a class="sve-badge" href="{{ cat.url }}">{{ cat.name }} ({{ cat.count }})</a></sve-loop></div>' },
  { id: 'd-archive', name: 'Archive list', group: 'dynamic', needsKit: true, hint: 'The page\'s own posts (archive / search)', html: '<div class="sve-stack"><h1 class="sve-h1">{{ archive.title }}</h1><div class="sve-grid" style="--cols:3"><sve-loop source="main" as="item"><article class="sve-card"><a href="{{ item.url }}"><img src="{{ item.image.medium_large }}" alt="" loading="lazy"></a><h3 class="sve-h3">{{ item.title }}</h3><p class="sve-text">{{ item.excerpt | words:18 }}</p></article></sve-loop></div><sve-pagination></sve-pagination></div>' },
  { id: 'd-related', name: 'Related posts', group: 'dynamic', needsKit: true, html: '<div class="sve-grid" style="--cols:3"><sve-loop source="related" query=\'{"posts_per_page":3}\' as="item"><a class="sve-card" href="{{ item.url }}" style="text-decoration:none"><img src="{{ item.image.medium }}" alt=""><h3 class="sve-h3">{{ item.title }}</h3></a></sve-loop></div>' },
  { id: 'd-menu', name: 'Menu', group: 'dynamic', needsKit: true, html: '<nav class="sve-nav"><sve-menu location="primary" class="sve-menu" depth="1"></sve-menu></nav>' },
  { id: 'd-search', name: 'Search box', group: 'dynamic', html: '<sve-search placeholder="Search…"></sve-search>' },
  { id: 'd-bread', name: 'Breadcrumbs', group: 'dynamic', needsKit: true, html: '<sve-breadcrumbs></sve-breadcrumbs>' },
  { id: 'd-cart', name: 'Cart link', group: 'dynamic', woo: true, html: '<a href="{{ cart.url }}" class="sve-btn sve-btn--ghost">🛒 {{ cart.count }}</a>' },
  { id: 'd-user', name: 'Account link', group: 'dynamic', html: '{{#if user.logged_in}}<a href="{{ user.account_url }}">{{ user.name }}</a>{{else}}<a href="{{ user.login_url }}">Log in</a>{{/if}}' },
  { id: 'd-year', name: 'Copyright', group: 'dynamic', html: '<p>© {{ site.year }} {{ site.name }}</p>' },
  { id: 'd-shortcode', name: 'Shortcode', group: 'dynamic', hint: 'Any plugin shortcode', html: '<sve-shortcode>[contact-form-7 id="1"]</sve-shortcode>' },
  { id: 'd-block', name: 'Gutenberg block', group: 'dynamic', hint: 'Any registered block', html: '<sve-block name="core/latest-posts" attrs=\'{"postsToShow":3}\'></sve-block>' },

  /* ---------------- sections ---------------- */
  { id: 's-hero', name: 'Hero — centered', group: 'sections', needsKit: true, html: '<section class="sve-section sve-soft"><div class="sve-container sve-narrow sve-stack sve-center"><span class="sve-eyebrow">Discover more</span><h1 class="sve-h1">Unforgettable trips, planned for you</h1><p class="sve-lead">Handpicked tours, honest prices and support from the first click to the last day.</p><div class="sve-row sve-center"><a class="sve-btn" href="#">Explore tours</a><a class="sve-btn sve-btn--outline" href="#">Talk to us</a></div></div></section>' },
  { id: 's-hero-split', name: 'Hero — with image', group: 'sections', needsKit: true, html: `<section class="sve-section"><div class="sve-container sve-split"><div class="sve-stack"><span class="sve-badge">★ 4.9 from 2,000+ travellers</span><h1 class="sve-h1">Your next island escape starts here</h1><p class="sve-lead">All-inclusive packages with flights, hotels and guided tours.</p><div class="sve-row"><a class="sve-btn" href="#">Book now</a><a class="sve-btn sve-btn--ghost" href="#">See packages</a></div></div><img class="sve-media" src="${img(1100, 900)}" alt="" style="aspect-ratio:1/1"></div></section>` },
  { id: 's-features', name: 'Features', group: 'sections', needsKit: true, html: '<section class="sve-section"><div class="sve-container sve-stack" style="gap:40px"><div class="sve-narrow sve-stack sve-center"><span class="sve-eyebrow">Why us</span><h2 class="sve-h2">Everything you need, nothing you don\'t</h2></div><div class="sve-grid" style="--cols:3"><div class="sve-card"><div class="sve-icon">✈</div><h3 class="sve-h3">Flights included</h3><p class="sve-text">Direct flights with trusted airlines.</p></div><div class="sve-card"><div class="sve-icon">🏝</div><h3 class="sve-h3">Best hotels</h3><p class="sve-text">Beachfront stays, verified by our team.</p></div><div class="sve-card"><div class="sve-icon">☎</div><h3 class="sve-h3">24/7 support</h3><p class="sve-text">Real people, any time you need them.</p></div></div></div></section>' },
  { id: 's-stats', name: 'Stats', group: 'sections', needsKit: true, html: '<section class="sve-section sve-soft"><div class="sve-container sve-grid sve-center" style="--cols:4"><div class="sve-stat"><b>12k+</b><span class="sve-text">Happy travellers</span></div><div class="sve-stat"><b>48</b><span class="sve-text">Destinations</span></div><div class="sve-stat"><b>4.9★</b><span class="sve-text">Average rating</span></div><div class="sve-stat"><b>15</b><span class="sve-text">Years of experience</span></div></div></section>' },
  { id: 's-logos', name: 'Logo strip', group: 'sections', needsKit: true, html: '<section class="sve-section" style="padding-block:40px"><div class="sve-container sve-stack sve-center"><p class="sve-text">Trusted by</p><div class="sve-logo-strip"><b>Emirates</b><b>Qatar</b><b>Marriott</b><b>Hilton</b><b>Booking</b></div></div></section>' },
  { id: 's-testimonials', name: 'Testimonials', group: 'sections', needsKit: true, html: '<section class="sve-section"><div class="sve-container sve-stack" style="gap:40px"><h2 class="sve-h2 sve-center" style="text-align:center">What travellers say</h2><div class="sve-grid" style="--cols:3"><figure class="sve-card"><p class="sve-quote">“Perfectly organised from start to finish.”</p><figcaption class="sve-row"><b>Neda R.</b><span class="sve-text">Phuket, 2025</span></figcaption></figure><figure class="sve-card"><p class="sve-quote">“The guides were amazing and so friendly.”</p><figcaption class="sve-row"><b>Ali M.</b><span class="sve-text">Bali, 2025</span></figcaption></figure><figure class="sve-card"><p class="sve-quote">“Great value. We are already planning the next one.”</p><figcaption class="sve-row"><b>Sara K.</b><span class="sve-text">Dubai, 2024</span></figcaption></figure></div></div></section>' },
  { id: 's-pricing', name: 'Pricing', group: 'sections', needsKit: true, html: '<section class="sve-section sve-soft"><div class="sve-container sve-stack" style="gap:40px"><h2 class="sve-h2" style="text-align:center">Choose your package</h2><div class="sve-grid" style="--cols:3"><div class="sve-card"><h3 class="sve-h3">Basic</h3><div class="sve-price">$499</div><ul class="sve-list"><li>Flights</li><li>3-star hotel</li></ul><a class="sve-btn sve-btn--outline" href="#">Choose</a></div><div class="sve-card" style="border:2px solid var(--sve-primary)"><span class="sve-badge">Most popular</span><h3 class="sve-h3">Comfort</h3><div class="sve-price">$899</div><ul class="sve-list"><li>Flights</li><li>4-star hotel</li><li>2 guided tours</li></ul><a class="sve-btn" href="#">Choose</a></div><div class="sve-card"><h3 class="sve-h3">Luxury</h3><div class="sve-price">$1,499</div><ul class="sve-list"><li>Business flights</li><li>5-star resort</li><li>Private guide</li></ul><a class="sve-btn sve-btn--outline" href="#">Choose</a></div></div></div></section>' },
  { id: 's-faq', name: 'FAQ', group: 'sections', needsKit: true, html: '<section class="sve-section"><div class="sve-container sve-narrow sve-stack"><h2 class="sve-h2">Frequently asked questions</h2><div class="sve-faq"><details><summary>Do I need a visa?</summary><p>It depends on your passport — we check it for you when you book.</p></details><details><summary>Can I pay in instalments?</summary><p>Yes, up to three instalments without extra cost.</p></details><details><summary>What if I need to cancel?</summary><p>Free cancellation up to 14 days before departure.</p></details></div></div></section>' },
  { id: 's-cta', name: 'Call to action', group: 'sections', needsKit: true, html: '<section class="sve-section"><div class="sve-container"><div class="sve-card sve-dark sve-center" style="padding:clamp(32px,6vw,72px);text-align:center"><h2 class="sve-h2">Ready for your next adventure?</h2><p class="sve-lead">Tell us where you want to go — we will plan the rest.</p><div class="sve-row sve-center"><a class="sve-btn" href="#">Plan my trip</a></div></div></div></section>' },
  { id: 's-newsletter', name: 'Newsletter', group: 'sections', needsKit: true, html: '<section class="sve-section sve-soft"><div class="sve-container sve-narrow sve-stack sve-center" style="text-align:center"><h2 class="sve-h2">Deals in your inbox</h2><p class="sve-lead">One email a month. Only the best offers.</p><form class="sve-form sve-row" data-sve-form="newsletter" data-sve-success="You are subscribed!"><input name="email" type="email" placeholder="you@example.com" required style="flex:1;min-width:220px"><button class="sve-btn" type="submit">Subscribe</button></form></div></section>' },
  { id: 's-gallery', name: 'Gallery', group: 'sections', needsKit: true, html: `<section class="sve-section"><div class="sve-container sve-grid" style="--cols:3"><img class="sve-media" src="${img(600, 600, 'a')}" alt="" style="aspect-ratio:1"><img class="sve-media" src="${img(600, 600, 'b')}" alt="" style="aspect-ratio:1"><img class="sve-media" src="${img(600, 600, 'c')}" alt="" style="aspect-ratio:1"></div></section>` },
  { id: 's-blog', name: 'Latest posts', group: 'sections', needsKit: true, html: '<section class="sve-section"><div class="sve-container sve-stack" style="gap:32px"><div class="sve-row" style="justify-content:space-between"><h2 class="sve-h2">From the blog</h2><a class="sve-btn sve-btn--ghost" href="/blog/">All posts</a></div><div class="sve-grid" style="--cols:3"><sve-loop query=\'{"post_type":"post","posts_per_page":3}\' as="item"><a class="sve-card" href="{{ item.url }}" style="text-decoration:none"><img src="{{ item.image.medium_large }}" alt="" loading="lazy"><span class="sve-text">{{ item.date }}</span><h3 class="sve-h3">{{ item.title }}</h3></a></sve-loop></div></div></section>' },
  { id: 's-products', name: 'Featured products', group: 'sections', woo: true, needsKit: true, html: '<section class="sve-section sve-soft"><div class="sve-container sve-stack" style="gap:32px"><h2 class="sve-h2">Popular tours</h2><div class="sve-grid" style="--cols:4"><sve-loop query=\'{"post_type":"product","posts_per_page":4,"orderby":"popularity"}\' as="item"><article class="sve-card"><a href="{{ item.url }}"><img src="{{ item.image.medium_large }}" alt="{{ item.title }}" loading="lazy" style="aspect-ratio:4/3"></a><h3 class="sve-h3">{{ item.title }}</h3><div>{{{ item.price_html }}}</div><a class="sve-btn" href="{{ item.url }}">View</a></article></sve-loop></div></div></section>' },
  { id: 's-contact', name: 'Contact', group: 'sections', needsKit: true, html: '<section class="sve-section"><div class="sve-container sve-split" style="align-items:start"><div class="sve-stack"><h2 class="sve-h2">Get in touch</h2><p class="sve-lead">We answer within one business day.</p><p class="sve-text">📞 +98 21 0000 0000<br>✉ hello@example.com<br>📍 Tehran, Iran</p></div><form class="sve-card sve-form sve-stack" data-sve-form="contact" data-sve-success="Thanks! We will reply soon."><label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label><label>Message<textarea name="message" rows="5" required></textarea></label><button class="sve-btn" type="submit">Send message</button></form></div></section>' },
  { id: 's-header', name: 'Header', group: 'sections', needsKit: true, hint: 'Logo, menu and button', html: '<header class="sve-header"><div class="sve-container"><a href="{{ site.url }}" style="font-weight:800;font-size:1.25rem;text-decoration:none;color:inherit">{{ site.name }}</a><nav class="sve-nav"><sve-menu location="primary" class="sve-menu" depth="1"></sve-menu></nav><a class="sve-btn" href="#">Book now</a></div></header>' },
  { id: 's-footer', name: 'Footer', group: 'sections', needsKit: true, html: '<footer class="sve-footer"><div class="sve-container sve-grid" style="--cols:4"><div class="sve-stack"><h4>{{ site.name }}</h4><p>{{ site.description }}</p></div><div class="sve-stack"><h4>Company</h4><a href="#">About</a><a href="#">Careers</a></div><div class="sve-stack"><h4>Help</h4><a href="#">FAQ</a><a href="#">Contact</a></div><div class="sve-stack"><h4>Follow</h4><a href="#">Instagram</a><a href="#">Telegram</a></div></div><p class="sve-container" style="margin-top:40px;font-size:.9rem">© {{ site.year }} {{ site.name }}</p></footer>' },

  /* ---------------- UI kit (shadcn-style) ---------------- */
  { id: 'k-buttons', name: 'Buttons', group: 'kit', needsKit: true, html: '<div class="sve-row"><a class="sve-btn" href="#">Primary</a><a class="sve-btn sve-btn--outline" href="#">Outline</a><a class="sve-btn sve-btn--ghost" href="#">Ghost</a></div>' },
  { id: 'k-alert', name: 'Alert', group: 'kit', needsKit: true, html: '<div class="sve-alert" role="alert"><b>Heads up!</b><span class="sve-text">You can change this message any time.</span></div>' },
  { id: 'k-input', name: 'Input with label', group: 'kit', needsKit: true, html: '<label class="sve-form" style="display:grid;gap:6px"><span>Email</span><input class="sve-input" type="email" name="email" placeholder="you@example.com"></label>' },
  { id: 'k-avatar', name: 'Avatar + name', group: 'kit', needsKit: true, html: '<div class="sve-row"><img class="sve-avatar" src="https://i.pravatar.cc/88?img=12" alt=""><div><b>Pouya</b><div class="sve-text">Travel expert</div></div></div>' },
  { id: 'k-card-stat', name: 'Stat card', group: 'kit', needsKit: true, html: '<div class="sve-card"><span class="sve-text">Bookings this month</span><b class="sve-price">1,284</b><span class="sve-badge">+12%</span></div>' },
];

/** shadcn/ui-style React islands (Radix primitives, loaded from esm.sh). Administrators only. */
export const ISLANDS: { name: string; jsx: string; html: string; css: string }[] = [
  {
    name: 'Tabs',
    html: '<div class="ui-tabs"><div class="ui-tabs-list"><button>Overview</button><button>Itinerary</button><button>Prices</button></div><div class="ui-tabs-panel">Overview content</div></div>',
    css: '.ui-tabs-list{display:inline-flex;gap:4px;padding:4px;border-radius:12px;background:var(--sve-soft,#f1f5f9)}.ui-tabs-list button{border:0;background:transparent;padding:8px 14px;border-radius:9px;font:inherit;cursor:pointer;color:var(--sve-muted,#64748b)}.ui-tabs-list button[data-state=active]{background:#fff;color:var(--sve-text,#0f172a);box-shadow:0 1px 3px rgb(0 0 0/.08)}.ui-tabs-panel{padding:20px 4px}',
    jsx: `import * as Tabs from "@radix-ui/react-tabs";

export default function UiTabs({ tabs = [{ title: "Overview", body: "Write here" }, { title: "Itinerary", body: "Day by day" }, { title: "Prices", body: "From $499" }] }) {
  return (
    <Tabs.Root className="ui-tabs" defaultValue="0">
      <Tabs.List className="ui-tabs-list">
        {tabs.map((t, i) => <Tabs.Trigger key={i} value={String(i)}>{t.title}</Tabs.Trigger>)}
      </Tabs.List>
      {tabs.map((t, i) => <Tabs.Content key={i} value={String(i)} className="ui-tabs-panel">{t.body}</Tabs.Content>)}
    </Tabs.Root>
  );
}`,
  },
  {
    name: 'Dialog',
    html: '<button class="sve-btn">Open details</button>',
    css: '.ui-overlay{position:fixed;inset:0;background:rgb(15 23 42/.55);backdrop-filter:blur(4px);z-index:999}.ui-dialog{position:fixed;z-index:1000;left:50%;top:50%;transform:translate(-50%,-50%);width:min(520px,calc(100vw - 32px));background:#fff;border-radius:16px;padding:28px;box-shadow:0 30px 80px rgb(0 0 0/.3)}.ui-dialog h2{margin:0 0 8px}',
    jsx: `import * as Dialog from "@radix-ui/react-dialog";

export default function UiDialog({ button = "Open details", title = "Trip details", text = "Everything about this trip." }) {
  return (
    <Dialog.Root>
      <Dialog.Trigger className="sve-btn">{button}</Dialog.Trigger>
      <Dialog.Portal>
        <Dialog.Overlay className="ui-overlay" />
        <Dialog.Content className="ui-dialog">
          <Dialog.Title>{title}</Dialog.Title>
          <Dialog.Description>{text}</Dialog.Description>
          <Dialog.Close className="sve-btn sve-btn--ghost" style={{ marginTop: 20 }}>Close</Dialog.Close>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}`,
  },
  {
    name: 'Counter',
    html: '<button class="sve-btn">Travellers: 1</button>',
    css: '',
    jsx: `import { useState } from "react";

export default function Counter({ label = "Travellers", start = 1 }) {
  const [n, setN] = useState(start);
  return (
    <div style={{ display: "inline-flex", gap: 8, alignItems: "center" }}>
      <button className="sve-btn sve-btn--ghost" onClick={() => setN(Math.max(1, n - 1))}>−</button>
      <b>{label}: {n}</b>
      <button className="sve-btn sve-btn--ghost" onClick={() => setN(n + 1)}>+</button>
    </div>
  );
}`,
  },
];
