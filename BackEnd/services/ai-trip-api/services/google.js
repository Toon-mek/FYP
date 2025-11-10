const axios = require('axios');
const KEY = process.env.GOOGLE_API_KEY;
const SERVER_URL = process.env.SERVER_URL || 'http://localhost:4000';

// Fallback coordinates for common Malaysian locations
const MALAYSIA_LOCATIONS = {
  'kuala lumpur': { lat: 3.1390, lng: 101.6869, formatted: 'Kuala Lumpur, Malaysia' },
  'penang': { lat: 5.4141, lng: 100.3288, formatted: 'Penang, Malaysia' },
  'penang island': { lat: 5.4141, lng: 100.3288, formatted: 'Penang, Malaysia' },
  'melaka': { lat: 2.1896, lng: 102.2501, formatted: 'Melaka, Malaysia' },
  'malacca': { lat: 2.1896, lng: 102.2501, formatted: 'Malacca, Malaysia' },
  'johor': { lat: 1.4927, lng: 103.7414, formatted: 'Johor Bahru, Malaysia' },
  'ipoh': { lat: 4.5975, lng: 101.0901, formatted: 'Ipoh, Malaysia' },
  'langkawi': { lat: 6.3500, lng: 99.8000, formatted: 'Langkawi, Malaysia' },
  'malaysia': { lat: 4.2105, lng: 101.9758, formatted: 'Malaysia' }
};

function normalizeLocation(location) {
  if (!location) return null;
  if (typeof location === 'object' && 'lat' in location && 'lng' in location) return location;
  const cleaned = String(location).toLowerCase().trim();
  for (const [key, val] of Object.entries(MALAYSIA_LOCATIONS)) {
    if (cleaned.includes(key)) return val;
  }
  return null;
}

async function geocodeAddress(address) {
  try {
    if (!address) throw new Error('Empty address');
    const normalized = normalizeLocation(address);
    if (normalized) return normalized;

    const searchAddress = String(address).toLowerCase().includes('malaysia') ? address : `${address}, Malaysia`;
    const url = `https://maps.googleapis.com/maps/api/geocode/json`;
    const r = await axios.get(url, { params: { address: searchAddress, key: KEY, region: 'my', components: 'country:MY' } });
    const res = r.data;
    if (!res.results || !res.results[0]) throw new Error('Geocode returned no results');

    // Ensure result is within Malaysia when possible
    const addrComp = res.results[0].address_components || [];
    const countryMatch = addrComp.some(c => c.types && c.types.includes('country') && (c.short_name === 'MY' || (c.long_name || '').toLowerCase().includes('malaysia')));
    if (!countryMatch) {
      // If Google returns a non-Malaysia result but user asked a Malaysian place, try fallback
      const fallback = normalizeLocation(address);
      if (fallback) return fallback;
    }

    const loc = res.results[0].geometry.location;
    return { lat: loc.lat, lng: loc.lng, formatted: res.results[0].formatted_address };
  } catch (err) {
    // Last-chance fallback: match simple city keywords
    const fallback = normalizeLocation(address);
    if (fallback) return fallback;
    throw new Error(`Could not geocode "${address}". Try a major Malaysian city or landmark.`);
  }
}

// Haversine helper (meters)
function haversine(a, b) {
  const toRad = v => (v * Math.PI) / 180;
  const R = 6371e3;
  const dLat = toRad(b.lat - a.lat);
  const dLon = toRad(b.lng - a.lng);
  const lat1 = toRad(a.lat);
  const lat2 = toRad(b.lat);
  const sinDlat = Math.sin(dLat / 2);
  const sinDlon = Math.sin(dLon / 2);
  const h = sinDlat * sinDlat + sinDlon * sinDlon * Math.cos(lat1) * Math.cos(lat2);
  const d = 2 * R * Math.asin(Math.sqrt(h));
  return d; // meters
}

async function getDistanceDuration(origin, destination) {
  try {
    // Accept origin as string/object; normalize coordinates where possible
    const normDest = normalizeLocation(destination) || (typeof destination === 'object' && 'lat' in destination ? destination : await geocodeAddress(destination));
    const normOrigin = normalizeLocation(origin) || (typeof origin === 'object' && 'lat' in origin ? origin : (origin ? await geocodeAddress(origin) : null));

    // If origin missing, default to country center for Malaysia
    const finalOrigin = normOrigin || MALAYSIA_LOCATIONS['malaysia'];
    const origins = `${finalOrigin.lat},${finalOrigin.lng}`;
    const destinations = `${normDest.lat},${normDest.lng}`;

    const url = `https://maps.googleapis.com/maps/api/distancematrix/json`;
    const r = await axios.get(url, { params: { origins, destinations, key: KEY, units: 'metric' } });
    const e = r.data;

    // Validate results
    if (!e.rows || !e.rows[0] || !e.rows[0].elements || !e.rows[0].elements[0]) {
      throw new Error('DistanceMatrix returned invalid payload');
    }
    const elem = e.rows[0].elements[0];
    if (elem.status && elem.status !== 'OK') {
      // If Distance Matrix cannot compute, fallback to haversine estimate
      const meters = haversine(finalOrigin, normDest);
      const km = meters / 1000;
      const avgSpeedKmh = 70; // conservative driving speed
      const hours = km / avgSpeedKmh;
      return {
        distance: { text: `${km.toFixed(1)} km (approx)`, value: Math.round(meters) },
        duration: { text: `${Math.round(hours * 60)} mins (approx)`, value: Math.round(hours * 3600) },
        raw: e
      };
    }

    // Standard successful response
    return {
      distance: elem.distance || { text: 'Unknown', value: 0 },
      duration: elem.duration || { text: 'Unknown', value: 0 },
      raw: e
    };
  } catch (err) {
    // Graceful fallback: compute by coordinates if possible
    try {
      const normDest = normalizeLocation(destination) || (typeof destination === 'object' && 'lat' in destination ? destination : null);
      const normOrigin = normalizeLocation(origin) || (typeof origin === 'object' && 'lat' in origin ? origin : null);
      if (normOrigin && normDest) {
        const meters = haversine(normOrigin, normDest);
        const km = meters / 1000;
        const avgSpeedKmh = 70;
        const hours = km / avgSpeedKmh;
        return {
          distance: { text: `${km.toFixed(1)} km (approx)`, value: Math.round(meters) },
          duration: { text: `${Math.round(hours * 60)} mins (approx)`, value: Math.round(hours * 3600) },
          raw: null
        };
      }
    } catch (e) { /* ignore */ }

    // Final generic fallback
    return {
      distance: { text: 'Unknown distance', value: 0 },
      duration: { text: 'Unknown duration', value: 0 },
      rawError: err.message
    };
  }
}

async function searchPlacesByTheme(theme, location, budgetRange) {
	const mapping = { 'Food': 'best local food', 'Culture': 'museum cultural centre', 'Relax': 'spa cafe relaxing' };
	const query = mapping[theme] || theme;
	const loc = normalizeLocation(location) || location;
	const url = `https://maps.googleapis.com/maps/api/place/nearbysearch/json`;
	const params = {
		key: KEY,
		location: `${loc.lat},${loc.lng}`,
		radius: 5000,
		keyword: query,
	};
	const r = await axios.get(url, { params });
	const items = (r.data.results || []).slice(0, 8).map(p => {
    // prefer a single string thumbnail; use server proxy if photo_reference exists
    let thumbnail = null;
    let photo_reference = null;
    try {
      if (p.photos && p.photos.length && p.photos[0].photo_reference) {
        photo_reference = p.photos[0].photo_reference;
        thumbnail = `${SERVER_URL}/api/photo?ref=${encodeURIComponent(photo_reference)}&maxwidth=400`;
      } else if (p.icon && typeof p.icon === 'string') {
        thumbnail = p.icon;
      }
    } catch (e) {
      thumbnail = null;
    }

    return {
      place_id: p.place_id,
      name: p.name,
      address: p.vicinity || p.formatted_address,
      lat: p.geometry.location.lat,
      lng: p.geometry.location.lng,
      rating: p.rating,
      price_level: p.price_level,
      thumbnail, // string or null
      photo_reference // optional raw reference
    };
  });
	return items;
}

module.exports = { geocodeAddress, getDistanceDuration, searchPlacesByTheme, normalizeLocation };