const axios = require('axios');

async function generateItinerary(context) {
	const prompt = [
		`You are an AI trip planner. User is travelling from ${formatLoc(context.currentLocation)} to ${context.destination.formatted}.`,
		`Estimated travel: ${context.travelEstimate.duration?.text || ''}, distance: ${context.travelEstimate.distance?.text || ''}.`,
		`Dates: ${context.dateRange?.start} to ${context.dateRange?.end}. Preferences: ${JSON.stringify(context.preferences)}.`,
		`Available places and stays (sample): ${JSON.stringify({ places: context.assets.places?.slice(0,5), stays: context.assets.stays?.slice(0,5) })}.`,
		`Produce a day-by-day itinerary consistent with budget and accommodation style, return JSON: { title, days:[{date, slots:[{time, type, name, address, notes, estimated_cost}]}], summary }`
	].join('\n');

	try {
		if (process.env.GEMINI_ENDPOINT && process.env.GEMINI_API_KEY) {
			const r = await axios.post(process.env.GEMINI_ENDPOINT, { prompt }, { headers: { Authorization: `Bearer ${process.env.GEMINI_API_KEY}` } });
			const out = r.data?.output || r.data;
			try { return typeof out === 'string' ? JSON.parse(out) : out; } catch (e) { return { raw: out, note: 'parsed as text' }; }
		}
	} catch (e) {
		console.warn('Gemini call failed, falling back', e.message);
	}

	return fallbackItinerary(context);
}

function formatLoc(loc){ if (!loc) return 'unknown'; if (typeof loc === 'string') return loc; return `${loc.lat},${loc.lng}`; }

function fallbackItinerary(context) {
	const days = [];
	const start = context.dateRange?.start ? new Date(context.dateRange.start) : new Date();
	const end = context.dateRange?.end ? new Date(context.dateRange.end) : start;
	const length = Math.max(1, Math.ceil((end - start) / (1000*60*60*24)) || 1);
	for (let i=0;i<length;i++){
		const date = new Date(start); date.setDate(date.getDate()+i);
		const dayPlaces = (context.assets.places[i] && context.assets.places[i].items) ? context.assets.places[i].items.slice(0,3) : (context.assets.places[0]?.items || []).slice(0,3);
		days.push({
			date: date.toISOString().slice(0,10),
			slots: [
				{ time: 'Morning', type: 'transfer', name: 'Travel to destination', notes: context.travelEstimate.duration?.text || '' },
				...dayPlaces.map((p, idx) => ({ time: idx===0?'Late Morning':'Afternoon', type: 'place', name: p.name, address: p.address, estimated_cost: p.price_level || null }))
			]
		});
	}
	return { title: `Auto itinerary to ${context.destination.formatted}`, days, summary: `Generated ${days.length} day(s)` };
}

module.exports = { generateItinerary };