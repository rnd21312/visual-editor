/** Page components the PHP templates can mount (`data-page` on #stz-root). */
export type PageName =
  | 'Home'
  | 'ToursArchive'
  | 'TourSingle'
  | 'Page'
  | 'Articles'
  | 'Article'
  | 'PlanMyTrip'
  | 'BookingSuccess'
  | 'NotFound';

export type MenuItem = {
  id: number;
  label: string;
  url: string;
  current: boolean;
  children: MenuItem[];
};

/* ---------------------------------------------------------------------------
 * Public REST API (namespace stz/v1). Money is always integer minor units.
 * ------------------------------------------------------------------------ */

export type Currency = {
  code: string;
  symbol: string;
  position: 'before' | 'after';
  decimals: number;
  thousand_separator: string;
  decimal_separator: string;
  minor_unit: number;
};

export type ImageData = {
  id: number;
  url: string;
  srcset: string;
  width: number;
  height: number;
  alt: string;
};

export type BadgeType = 'discount' | 'last_minute' | 'few_seats' | 'special_offer' | 'sold_out';

export type Badge = { type: BadgeType; label: string; seats?: number };

export type Term = { id: number; slug: string; name: string };

export type Rating = { average: number; count: number };

export type NextDeparture = {
  id: number;
  start_date: string;
  end_date: string;
  seats_left: number;
};

/** GET /tours item, also the base of GET /tours/{id}. */
export type TourCard = {
  id: number;
  slug: string;
  url: string;
  title: string;
  excerpt: string;
  image: ImageData | null;
  duration_days: number;
  destinations: Term[];
  styles: Term[];
  featured: boolean;
  rating: Rating;
  meeting_point: string;
  includes_preview: string[];
  price_from: number | null;
  /** Regular price when `price_from` is a sale price. */
  price_from_regular: number | null;
  price_from_plan: string | null;
  currency_code: string;
  next_departure: NextDeparture | null;
  bookable: boolean;
  badges: Badge[];
};

export type ToursResponse = {
  items: TourCard[];
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
  currency: Currency;
};

export type ToursQuery = Partial<{
  destination: string;
  style: string;
  /** YYYY-MM */
  month: string;
  duration_min: number;
  duration_max: number;
  /** Minor units. */
  price_min: number;
  price_max: number;
  pax: number;
  discount: boolean;
  last_minute: boolean;
  special_offer: boolean;
  featured: boolean;
  search: string;
  include: number[];
  sort: 'recommended' | 'price_asc' | 'price_desc' | 'date_asc' | 'duration_asc' | 'newest';
  page: number;
  per_page: number;
}>;

export type PricingPlan = {
  id: string;
  label: string;
  pax: number;
  price: number;
  sale_price: number | null;
  extra_person_price?: number;
  max_pax?: number;
};

export type TourExtra = {
  id: string;
  label: string;
  price: number;
  unit: 'per_booking' | 'per_person';
  max_qty: number | null;
};

export type ItineraryItem = {
  time: string;
  type: 'transfer' | 'meal' | 'activity' | 'rest' | 'free' | 'stay';
  title: string;
};

export type ItineraryDay = {
  day: number;
  title: string;
  description: string;
  items: ItineraryItem[];
};

export type PlanPrice = { price: number; sale_price: number | null; effective: number };

export type Departure = {
  id: number;
  start_date: string;
  end_date: string;
  capacity: number;
  seats_left: number;
  status: 'open' | 'closed';
  bookable: boolean;
  note: string;
  /** Effective price per plan id for this departure. */
  prices: Record<string, PlanPrice>;
};

/** GET /tours/{id|slug} */
export type TourDetail = TourCard & {
  content: string;
  gallery: ImageData[];
  highlights: string[];
  includes: string[];
  excludes: string[];
  itinerary: ItineraryDay[];
  meeting_point: { name: string; address: string; lat: number | null; lng: number | null };
  pricing: { currency: string; plans: PricingPlan[] };
  extras: TourExtra[];
  departures: Departure[];
};

export type QuoteRequest = {
  departure_id: number;
  plan_id: string;
  pax?: number | null;
  extras?: { id: string; qty: number }[];
};

export type QuoteLine = {
  type: 'plan' | 'extra_person' | 'extra';
  id: string;
  label: string;
  qty: number;
  unit_amount: number;
  amount: number;
};

export type QuoteError = { field: string; code: string; message: string };

/** POST /quote */
export type QuoteResponse = {
  ok: boolean;
  errors: QuoteError[];
  total: number;
  pax: number;
  lines: QuoteLine[];
  currency: Currency;
  seats_left: number | null;
};

/** Payload printed by PHP in <script id="stz-data" type="application/json">. */
export type SiteData = {
  page: PageName;
  site: {
    name: string;
    description: string;
    url: string;
    toursUrl: string;
    blogUrl: string;
    planUrl: string;
    bookingSuccessUrl: string;
    restUrl: string;
    locale: string;
    pluginActive: boolean;
  };
  menus: {
    primary: MenuItem[];
    footer: MenuItem[];
  };
  contact: Contact;
  booking: { turnstileKey: string; terms: string };
  /** Shared copy: brand, planner options, support (FAQ/policy/about). */
  content: Partial<Pick<SiteContent, 'brand' | 'planner' | 'support'>>;
  currency: Currency | null;
  /** Destinations for the header dropdown. */
  destinations: NavDestination[];
  /** Travel styles for the footer. */
  styles: NavDestination[];
  /** Trail for the current page (without the home entry). */
  breadcrumbs: { name: string; url: string }[];
  /** Featured tours for the footer. */
  topTours: { id: number; title: string; url: string; duration_days: number; destination: string }[];
  /** Page-specific payload — each page casts it to its own type. */
  payload: Record<string, unknown>;
};

/* ---------------------------------------------------------------------------
 * Editorial content
 * ------------------------------------------------------------------------ */

export type Contact = {
  company?: string;
  phone?: string;
  whatsapp?: string;
  line?: string;
  telegram?: string;
  email?: string;
  response?: string;
  instagram?: string;
  facebook?: string;
};

export type NavDestination = { id: number; slug: string; name: string; url: string };

export type Destination = NavDestination & {
  description: string;
  count: number;
  parent: number;
  image: ImageData | null;
  order: number;
  tagline: string;
  eyebrow: string;
  vibe: string;
  best_season: string;
  ideal_stay: string;
  highlights: string[];
};

/** A travel style, shown as an "experience" card. */
export type TravelStyle = {
  id: number;
  slug: string;
  name: string;
  description: string;
  url: string;
  count: number;
  image: ImageData | null;
  order: number;
  category: string;
  location: string;
  duration: string;
};

export type Article = {
  id: number;
  slug: string;
  url: string;
  title: string;
  excerpt: string;
  image: ImageData | null;
  category: string;
  category_slug?: string;
  read_minutes: number;
  author: string;
  date: string;
  /** Only on the single-article payload. */
  content?: string;
};

export type ArticlesResponse = {
  items: Article[];
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
};

export type Review = {
  id: number;
  name: string;
  country: string;
  rating: number;
  review: string;
  date: string;
  verified: boolean;
  tour: { id: number; title: string; url: string };
};

export type ContactChannel = 'phone' | 'whatsapp' | 'line' | 'email';

export type BookingInput = QuoteRequest & {
  name: string;
  email: string;
  phone: string;
  contact_channel: ContactChannel;
  contact_handle: string;
  message: string;
  terms: boolean;
  /** Honeypot — must stay empty. */
  website: string;
  turnstile_token?: string;
};

export type BookingResponse = { ok: boolean; code: string; total: number; pax: number };

/** GET /bookings/{code}?email= */
export type BookingSummary = {
  code: string;
  status: string;
  tour: { title: string; url: string };
  start_date: string;
  end_date: string;
  plan: string;
  pax: number;
  total: number;
  currency: Currency;
  name: string;
  response_time: string;
};

export type ReviewsResponse = {
  items: Review[];
  total: number;
  total_pages: number;
  stats: Rating;
};

export type ReviewInput = {
  name: string;
  email: string;
  country: string;
  rating: number;
  review: string;
  /** Honeypot — must stay empty. */
  website: string;
};

export type TripRequestInput = {
  destination: string;
  style: string;
  season: string;
  duration: string;
  travelers: string;
  hotel: string;
  budget: string;
  interests: string[];
  name: string;
  email: string;
  phone: string;
  notes: string;
  tour_ids: number[];
  /** Honeypot — must stay empty. */
  website: string;
};

export type TripRequestResponse = { ok: boolean; reference: string; response_time: string };

type Section = { eyebrow?: string; title?: string; subtitle?: string };

/** Editable copy (defaults in suntourz-core/data/content-defaults.json). */
export type SiteContent = {
  brand: {
    badge: string;
    tagline: string;
    footer_pitch: string;
    license_title: string;
    license_note: string;
    top_bar_label: string;
    logo_image?: string;
    logo_image_white?: string;
    favicon_image?: string;
    copyright?: string;
  };
  design?: { color_primary: string; color_accent: string; color_background: string };
  sections?: { id: string; enabled: boolean }[];
  tours_page?: Section & { banner_image: string };
  hero: {
    eyebrow: string;
    title: string;
    title_accent: string;
    subtitle: string;
    image: string;
    image_alt: string;
    cues: string[];
  };
  featured: Section;
  destinations: Section;
  why: Section & { pillars: { icon: string; title: string; description: string }[] };
  experiences: Section;
  finder: Section;
  guide: Section & { badge: string; banner_image?: string };
  reviews: Section & {
    stats: { value: string; label: string }[];
    fallback: {
      id: string;
      name: string;
      country: string;
      tour: string;
      destination: string;
      rating: number;
      review: string;
      avatar: string;
      date: string;
    }[];
  };
  gallery: Section & {
    footnote: string;
    items: { title: string; category: string; location: string; image: string }[];
  };
  final_cta: {
    image: string;
    image_alt: string;
    eyebrow: string;
    title: string;
    title_accent: string;
    subtitle: string;
    cues: string[];
  };
  planner: {
    title: string;
    eyebrow: string;
    subtitle: string;
    destinations: string[];
    styles: string[];
    seasons: string[];
    durations: string[];
    travelers: string[];
    hotels: string[];
    budgets: string[];
    interests: string[];
  };
  support: {
    address: string;
    address_title: string;
    license_line: string;
    hours: string;
    promise_title: string;
    promise: string;
    guarantee: string;
    faqs: { q: string; a: string }[];
    policy: {
      title: string;
      effective: string;
      intro: string;
      rows: { notice: string; refund: string; reschedule: string; tone: 'good' | 'warn' | 'alert' | 'bad' }[];
      weather_title: string;
      weather: string;
      how_to_title: string;
      how_to_note: string;
    };
    about: {
      title: string;
      paragraphs: string[];
      badges: { title: string; text: string }[];
    };
  };
};

/* ---------------------------------------------------------------------------
 * Page payloads (what each PHP template prints for its React page)
 * ------------------------------------------------------------------------ */

export type HomePayload = {
  featured: TourCard[];
  toursTotal: number;
  finder: ToursResponse;
  destinations: Destination[];
  styles: TravelStyle[];
  articles: Article[];
  reviews: { items: Review[]; overall: Rating };
  content: SiteContent;
};

export type ArchivePayload = {
  page?: Section & { banner_image: string };
  heading: string;
  intro: string;
  preset: Record<string, string>;
  filters: ToursQuery;
  result: ToursResponse | null;
  destinations: Destination[];
  styles: TravelStyle[];
};

export type TourPayload = { tour: TourDetail; reviews: ReviewsResponse; related?: TourCard[] };
export type PagePayload = { title: string; content: string; image: string; tours?: TourCard[] };
export type ArticleTerm = { id: number; slug: string; name: string; count: number; url: string };

export type ArticleSort = 'newest' | 'oldest' | 'title_asc' | 'title_desc';

/** Filters of the blog listing (same names as GET /articles). */
export type ArticlesQuery = {
  search?: string;
  category?: string;
  tag?: string;
  sort?: ArticleSort;
  page?: number;
};

export type ArticlesPayload = {
  result: ArticlesResponse | null;
  content: Section & { badge?: string };
  heading?: string;
  intro?: string;
  /** Filters fixed by the route (category / tag archive). PHP encodes an empty map as []. */
  preset?: ArticlesQuery | [];
  filters?: ArticlesQuery | [];
  categories?: ArticleTerm[];
  tags?: ArticleTerm[];
  recent?: Article[];
};
export type ArticlePayload = { article: Article; related: Article[]; tours?: TourCard[] };
