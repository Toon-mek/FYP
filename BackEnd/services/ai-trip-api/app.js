const express = require('express');
const bodyParser = require('body-parser');
const dotenv = require('dotenv');
const axios = require('axios');
const fs = require('fs');
const path = require('path');
const google = require('./services/google');
const booking = require('./services/booking');
const ai = require('./services/ai');
const db = require('./db/localDb');

function loadEnv() {
	const localEnv = path.resolve(__dirname, '.env');
	const repoEnv = path.resolve(__dirname, '../../.env');

	if (fs.existsSync(localEnv)) {
		dotenv.config({ path: localEnv });
	}

	if (fs.existsSync(repoEnv)) {
		dotenv.config({ path: repoEnv, override: false });
	}
}

loadEnv();

const app = express();
app.use(bodyParser.json());
app.use((req, res, next) => { res.setHeader('Access-Control-Allow-Origin', '*'); res.setHeader('Access-Control-Allow-Headers', 'Content-Type'); next(); });

// Photo proxy: /api/photo?ref=PHOTO_REFERENCE&maxwidth=400
app.get('/api/photo', async (req, res) => {
	const { ref, maxwidth } = req.query;
	if (!ref) return res.status(400).send('missing photo reference');
	try {
		const key = process.env.GOOGLE_API_KEY;
		if (!key) return res.status(500).send('server misconfigured: missing GOOGLE_API_KEY');
		const url = `https://maps.googleapis.com/maps/api/place/photo`;
		// Use axios stream to proxy image
		const resp = await axios.get(url, { params: { photoreference: ref, maxwidth: maxwidth || 400, key }, responseType: 'stream', validateStatus: s => s < 500 });
		// forward headers and stream
		if (resp.status === 200) {
			res.setHeader('Cache-Control', 'public, max-age=86400');
			res.setHeader('Content-Type', resp.headers['content-type'] || 'image/jpeg');
			resp.data.pipe(res);
		} else {
			// Google may return redirect or non-200; try to pipe if stream present
			if (resp.data && resp.data.pipe) {
				resp.data.pipe(res);
			} else {
				res.status(502).send('photo fetch failed');
			}
		}
	} catch (err) {
		console.error('photo proxy error', err?.message || err);
		return res.status(502).send('photo proxy error');
	}
});

// POST /api/plan
// Body: { currentLocation, destination, dateRange, preferences }
app.post('/api/plan', async (req, res) => {
	const { currentLocation, destination, dateRange, preferences } = req.body;
	if (!destination || !preferences) return res.status(400).json({ error: 'destination and preferences required' });

	try {
		// 1) Geocode destination
		const destGeo = await google.geocodeAddress(destination);

		// 2) Distance/time estimation
		const travelEstimate = await google.getDistanceDuration(currentLocation || destGeo, destGeo);

		// 3) Based on themes, fetch places or accommodations
		const assets = { places: [], stays: [] };
		for (const theme of (preferences.themes || [])) {
			if (['Culture','Food','Relax'].includes(theme)) {
				const hits = await google.searchPlacesByTheme(theme, destGeo, preferences.budget);
				assets.places.push({ theme, items: hits });
			} else {
				const stays = await booking.searchAccommodations(destGeo, dateRange, preferences, theme);
				assets.stays.push({ theme, items: stays });
			}
		}

		// 4) Generate itinerary with AI orchestrator
		const context = { currentLocation, destination: destGeo, travelEstimate, dateRange, preferences, assets };
		const itinerary = await ai.generateItinerary(context);

		return res.json({ itinerary, context });
	} catch (err) {
		console.error(err);
		return res.status(500).json({ error: err.message || 'internal' });
	}
});

// POST /api/save -> save itinerary
app.post('/api/save', (req, res) => {
	const { itinerary, meta } = req.body;
	if (!itinerary) return res.status(400).json({ error: 'itinerary required' });
	const id = db.savePlan({ itinerary, meta, savedAt: new Date().toISOString() });
	return res.json({ ok: true, id });
});

const PORT = process.env.PORT || 4000;
app.listen(PORT, () => console.log(`AI Trip Planner API running on ${PORT}`));
