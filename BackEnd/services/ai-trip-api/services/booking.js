const axios = require('axios');
const RAPID_KEY = process.env.RAPIDAPI_KEY;
const RAPID_HOST = process.env.RAPIDAPI_HOST || 'booking-com.p.rapidapi.com';
const GOOGLE_KEY = process.env.GOOGLE_API_KEY;
const SERVER_URL = process.env.SERVER_URL || 'http://localhost:4000';

/* ...existing helper functions augmented and improved... */

// Try to parse numbers from many shapes
function tryParseNumber(v) {
  if (v == null) return null;
  if (typeof v === 'number') return v;
  if (typeof v === 'string') {
    const cleaned = v.replace(/[^0-9.]/g, '');
    const n = parseFloat(cleaned);
    return isNaN(n) ? null : n;
  }
  if (typeof v === 'object') {
    if ('value' in v && typeof v.value === 'number') return v.value;
    if ('amount' in v) return tryParseNumber(v.amount);
    if ('gross_amount' in v) return tryParseNumber(v.gross_amount);
    if ('price' in v) return tryParseNumber(v.price);
  }
  return null;
}

function formatPriceDisplay(value, currency, rawFormatted) {
  if (rawFormatted && typeof rawFormatted === 'string' && rawFormatted.trim()) return rawFormatted;
  if (value == null) return 'Price unavailable';
  const cur = (currency || 'MYR').toString().toUpperCase();
  if (cur === 'MYR' || cur === 'RM') return `RM ${Number(value).toFixed(2)}`;
  try {
    return new Intl.NumberFormat('en-MY', { style: 'currency', currency: cur }).format(Number(value));
  } catch (e) {
    return `${cur} ${Number(value).toFixed(2)}`;
  }
}

// Deep search for numeric price-like values in arbitrary object
function deepFindPrice(obj, seen = new Set()) {
  if (!obj || typeof obj !== 'object') return null;
  if (seen.has(obj)) return null;
  seen.add(obj);
  // Inspect keys likely to contain price
  const priceKeyPatterns = ['price', 'amount', 'total', 'gross', 'rate', 'cost', 'min_price', 'min_total', 'offer'];
  for (const k of Object.keys(obj)) {
    try {
      const val = obj[k];
      const lower = k.toLowerCase();
      if (priceKeyPatterns.some(p => lower.includes(p))) {
        const n = tryParseNumber(val);
        if (n != null) return n;
      }
    } catch (e) { /* ignore */ }
  }
  // Recurse
  for (const k of Object.keys(obj)) {
    try {
      const val = obj[k];
      if (val && typeof val === 'object') {
        const n = deepFindPrice(val, seen);
        if (n != null) return n;
      }
    } catch (e) { /* ignore */ }
  }
  return null;
}

// Extract price info robustly
function extractPriceInfo(h) {
  const formattedCandidates = [
    h.display_price,
    h.price_string,
    h.formatted_price,
    h.price_formatted,
    h.price_display,
    (h.offers && Array.isArray(h.offers) && h.offers[0] && (h.offers[0].display_price || h.offers[0].price_string))
  ];
  const rawFormatted = formattedCandidates.find(f => typeof f === 'string' && f.trim());

  const numericCandidates = [
    h.min_price,
    h.min_total_price,
    h.min_rate,
    h.price,
    (h.price && h.price.value),
    (h.price_breakdown && (h.price_breakdown.gross_price || h.price_breakdown.all_inclusive_amount || h.price_breakdown.total)),
    (h.composite_price_breakdown && (h.composite_price_breakdown.all_inclusive_amount || h.composite_price_breakdown.gross_amount)),
    (h.offers && Array.isArray(h.offers) && h.offers[0] && (h.offers[0].price || h.offers[0].price?.value || h.offers[0].price?.gross_amount)),
    (h.rate && h.rate.amount),
    (h.price_breakdown && h.price_breakdown.min_price)
  ];

  let priceVal = null;
  for (const c of numericCandidates) {
    const n = tryParseNumber(c);
    if (n != null) { priceVal = n; break; }
  }

  // fallback: deep search for any price-like numeric value
  if (priceVal == null) priceVal = deepFindPrice(h);

  let currency = null;
  const currencyCandidates = [
    h.currency,
    (h.price && h.price.currency),
    (h.price_breakdown && h.price_breakdown.currency),
    (h.offers && Array.isArray(h.offers) && h.offers[0] && (h.offers[0].currency || h.offers[0].price?.currency)),
    h.currency_code
  ];
  for (const c of currencyCandidates) {
    if (typeof c === 'string' && c.trim()) { currency = c.trim().toUpperCase(); break; }
  }
  if (!currency) currency = 'MYR';

  const price_display = formatPriceDisplay(priceVal, currency, rawFormatted && (Array.isArray(rawFormatted) ? rawFormatted[0] : rawFormatted));
  return { price: priceVal, currency, price_display };
}

// Address extraction
function extractAddress(h) {
  const addrCandidates = [
    h.address,
    h.hotel_address,
    (h.location && h.location.address),
    (h.hotel && h.hotel.address),
    (h.city && (h.city + (h.address ? (', ' + h.address) : ''))),
    (h.address_line || h.address1 || h.address_full),
    h.formatted_address
  ];
  for (const a of addrCandidates) {
    if (typeof a === 'string' && a.trim()) return a.trim();
  }
  if (h.location && typeof h.location === 'object') {
    const parts = [];
    if (h.location.address) parts.push(h.location.address);
    if (h.location.city) parts.push(h.location.city);
    if (h.location.country_name) parts.push(h.location.country_name);
    if (parts.length) return parts.join(', ');
  }
  return null;
}

// Review extraction
function extractReviewInfo(h) {
  let score = h.review_score || h.review_score_avg || h.review_score_value || (h.review && (h.review.score || h.review.average)) || null;
  if (typeof score === 'string') score = parseFloat(score) || null;

  let count = h.review_nr || h.review_count || h.reviews_count || h.num_reviews || (h.review && (h.review.count || h.review.total)) || null;
  if (typeof count === 'string') {
    const cleaned = count.replace(/[^0-9]/g, '');
    count = cleaned ? parseInt(cleaned, 10) : null;
  }

  const summary = h.review_score_word || h.review_summary || (h.review && h.review.summary) || null;
  return { review_score: score, review_count: count, review_summary: summary };
}

// Try to get coords from raw
function getCoordsFromRaw(h) {
  const latCandidates = [h.lat, h.latitude, h.location && h.location.lat, h.location && h.location.latitude, h.latitude_value];
  const lngCandidates = [h.lng, h.longitude, h.location && h.location.lng, h.location && h.location.longitude, h.longitude_value];
  const lat = latCandidates.find(x => typeof x === 'number');
  const lng = lngCandidates.find(x => typeof x === 'number');
  if (lat != null && lng != null) return { lat, lng };
  const latS = latCandidates.find(x => typeof x === 'string' && x.trim());
  const lngS = lngCandidates.find(x => typeof x === 'string' && x.trim());
  if (latS && lngS) {
    const la = parseFloat(latS);
    const lo = parseFloat(lngS);
    if (!isNaN(la) && !isNaN(lo)) return { lat: la, lng: lo };
  }
  return null;
}

// Use Google Places to enrich address, rating, reviews and photos if possible
async function enrichWithPlaceDetails(h) {
  if (!GOOGLE_KEY) return null;
  try {
    let placeId = null;
    let photoRef = null;
    const coords = getCoordsFromRaw(h);
    if (coords) {
      const nearbyUrl = `https://maps.googleapis.com/maps/api/place/nearbysearch/json`;
      const r = await axios.get(nearbyUrl, { params: { location: `${coords.lat},${coords.lng}`, radius: 200, keyword: h.hotel_name || h.name || '', key: GOOGLE_KEY } });
      const res = r.data;
      if (res && Array.isArray(res.results) && res.results[0]) {
        placeId = res.results[0].place_id;
        if (res.results[0].photos && res.results[0].photos[0] && res.results[0].photos[0].photo_reference) photoRef = res.results[0].photos[0].photo_reference;
      }
    }
    if (!placeId) {
      const query = `${h.hotel_name || h.name || ''} ${h.address || ''} Malaysia`.trim();
      const textUrl = `https://maps.googleapis.com/maps/api/place/textsearch/json`;
      const r2 = await axios.get(textUrl, { params: { query, key: GOOGLE_KEY } });
      const res2 = r2.data;
      if (res2 && Array.isArray(res2.results) && res2.results[0]) {
        placeId = res2.results[0].place_id;
        if (res2.results[0].photos && res2.results[0].photos[0] && res2.results[0].photos[0].photo_reference) photoRef = res2.results[0].photos[0].photo_reference;
      }
    }

    if (!placeId) return null;

    const detailsUrl = `https://maps.googleapis.com/maps/api/place/details/json`;
    const fields = ['formatted_address','rating','user_ratings_total','reviews','photos','website','formatted_phone_number'].join(',');
    const rd = await axios.get(detailsUrl, { params: { place_id: placeId, fields, key: GOOGLE_KEY } });
    const det = rd.data && rd.data.result ? rd.data.result : null;
    if (!det) return null;

    const formatted_address = det.formatted_address || null;
    const rating = det.rating != null ? det.rating : null;
    const review_count = det.user_ratings_total != null ? det.user_ratings_total : null;
    const reviews = Array.isArray(det.reviews) ? det.reviews.slice(0,3).map(rw => ({ author: rw.author_name, rating: rw.rating, text: rw.text })) : [];

    let thumbnail = null;
    if (det.photos && det.photos[0] && det.photos[0].photo_reference) {
      thumbnail = `${SERVER_URL}/api/photo?ref=${encodeURIComponent(det.photos[0].photo_reference)}&maxwidth=400`;
    } else if (photoRef) {
      thumbnail = `${SERVER_URL}/api/photo?ref=${encodeURIComponent(photoRef)}&maxwidth=400`;
    }

    return { formatted_address, rating, review_count, reviews, thumbnail };
  } catch (err) {
    console.warn('Place enrichment failed', err?.message || err);
    return null;
  }
}

/* searchAccommodations: main exported function (hotels) */
async function searchAccommodations(location, dateRange, preferences, theme) {
  try {
    const url = `https://${RAPID_HOST}/v1/hotels/search`;
    const params = {
      latitude: location.lat,
      longitude: location.lng,
      checkin_date: dateRange?.start,
      checkout_date: dateRange?.end,
      adults_number: preferences.groupSize || 2,
      rows: 30
    };
    const r = await axios.get(url, { params, headers: { 'x-rapidapi-key': RAPID_KEY, 'x-rapidapi-host': RAPID_HOST }, validateStatus: s => s < 500 });
    if (r.status === 429) {
      console.warn('Booking API rate limited (429)');
      return [];
    }
    const raw = r.data || {};
    const list = Array.isArray(raw.result) ? raw.result : (raw.data || raw.hotels || raw.results || []);
    const hotels = [];
    for (const h of (list || []).slice(0, 30)) {
      let thumbnail = null;
      try {
        if (typeof h.main_photo === 'string') thumbnail = h.main_photo;
        else if (h.main_photo && h.main_photo.url) thumbnail = h.main_photo.url;
        else if (h.photo && Array.isArray(h.photo) && h.photo[0] && h.photo[0].url) thumbnail = h.photo[0].url;
        else if (h.thumbnail && typeof h.thumbnail === 'string') thumbnail = h.thumbnail;
      } catch (e) { thumbnail = null; }

      const { price, currency, price_display } = extractPriceInfo(h);
      const rawAddress = extractAddress(h);
      const { review_score, review_count, review_summary } = extractReviewInfo(h);

      let placeEnrich = null;
      if (GOOGLE_KEY) {
        try {
          if (!rawAddress || review_score == null || thumbnail == null) {
            placeEnrich = await enrichWithPlaceDetails(h);
            await new Promise(res => setTimeout(res, 120));
          }
        } catch (e) { /* ignore */ }
      }

      const finalAddress = rawAddress || (placeEnrich && placeEnrich.formatted_address) || null;
      const finalRating = (review_score != null ? review_score : (placeEnrich && placeEnrich.rating != null ? placeEnrich.rating : null));
      const finalReviewCount = (review_count != null ? review_count : (placeEnrich && placeEnrich.review_count != null ? placeEnrich.review_count : null));
      const finalReviews = (placeEnrich && placeEnrich.reviews) || null;
      const finalThumbnail = thumbnail || (placeEnrich && placeEnrich.thumbnail) || null;

      if (price == null) {
        console.debug('Hotel price not found, sample keys:', Object.keys(h).slice(0,20));
      }

      hotels.push({
        id: h.hotel_id || h.id || (h.hotel && h.hotel.id) || null,
        name: h.hotel_name || h.name || (h.hotel && h.hotel.name) || 'Unknown',
        address: finalAddress,
        price,
        price_display,
        currency,
        rating: finalRating,
        review_count: finalReviewCount,
        review_summary: review_summary || null,
        reviews: finalReviews || null,
        thumbnail: finalThumbnail,
        type: preferences.accommodationStyle || 'Comfort',
        raw: h
      });
    }
    return hotels;
  } catch (err) {
    console.error('Booking search error', err?.message || err);
    return [];
  }
}

/* searchActivities: try RapidAPI activity endpoint, fallback to Google Places with price_level->estimate */
async function searchActivities(location, dateRange, preferences, themeQuery) {
  // Try RapidAPI activities endpoint (DataCrawler/Booking variants). If unavailable, fallback to Google Places.
  try {
    // A common RapidAPI path - many RapidAPI Booking wrappers differ; try one safe path first.
    const endpoint = `https://${RAPID_HOST}/v1/activities/search`;
    const params = { latitude: location.lat, longitude: location.lng, adults_number: preferences.groupSize || 2, query: themeQuery, rows: 30 };
    const r = await axios.get(endpoint, { params, headers: { 'x-rapidapi-key': RAPID_KEY, 'x-rapidapi-host': RAPID_HOST }, validateStatus: s => s < 500 });
    if (r.status === 200 && Array.isArray(r.data?.results) && r.data.results.length) {
      return r.data.results.map(a => {
        const priceInfo = extractPriceInfo(a);
        const addr = extractAddress(a);
        const { review_score, review_count } = extractReviewInfo(a);
        let thumb = null;
        try {
          if (a.photo && typeof a.photo === 'string') thumb = a.photo;
          else if (a.photos && Array.isArray(a.photos) && a.photos[0]) thumb = a.photos[0];
        } catch (e) { thumb = null; }
        return {
          id: a.id || a.activity_id || null,
          name: a.name || a.title || 'Unknown',
          address: addr,
          price: priceInfo.price,
          price_display: priceInfo.price_display,
          currency: priceInfo.currency,
          rating: review_score,
          review_count,
          thumbnail: thumb,
          raw: a
        };
      });
    }
  } catch (err) {
    // ignore and fallback
    console.debug('RapidAPI activities attempt failed:', err?.message || err);
  }

  // Fallback: use Google Places Text Search / NearbySearch
  if (!GOOGLE_KEY) return [];
  try {
    const query = `${themeQuery} near ${location.lat},${location.lng}`;
    const url = `https://maps.googleapis.com/maps/api/place/nearbysearch/json`;
    const r = await axios.get(url, { params: { key: GOOGLE_KEY, location: `${location.lat},${location.lng}`, radius: 10000, keyword: themeQuery } });
    const items = (r.data.results || []).slice(0, 30).map(p => {
      let thumbnail = null;
      if (p.photos && p.photos[0] && p.photos[0].photo_reference) {
        thumbnail = `${SERVER_URL}/api/photo?ref=${encodeURIComponent(p.photos[0].photo_reference)}&maxwidth=400`;
      } else if (p.icon) thumbnail = p.icon;
      // Map price_level to estimated display
      const pl = p.price_level; // 0-4
      let price_display = 'Price unavailable';
      if (pl === 0) price_display = 'Free / Low';
      else if (pl === 1) price_display = 'Low';
      else if (pl === 2) price_display = 'Moderate';
      else if (pl === 3) price_display = 'Expensive';
      else if (pl === 4) price_display = 'Very expensive';
      return {
        id: p.place_id,
        name: p.name,
        address: p.vicinity || p.formatted_address || null,
        price: null,
        price_display,
        currency: null,
        rating: p.rating || null,
        review_count: p.user_ratings_total || null,
        thumbnail,
        raw: p
      };
    });
    return items;
  } catch (err) {
    console.error('Google Places fallback for activities failed', err?.message || err);
    return [];
  }
}

module.exports = { searchAccommodations, searchActivities };