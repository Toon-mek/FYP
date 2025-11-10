const fs = require('fs');
const path = require('path');
const DBPATH = path.join(__dirname, '..', 'data', 'plans.json');

function _read() {
	try { const s = fs.readFileSync(DBPATH,'utf8'); return JSON.parse(s||'[]'); } catch(e){ return []; }
}
function _write(data){ fs.mkdirSync(path.dirname(DBPATH), { recursive: true }); fs.writeFileSync(DBPATH, JSON.stringify(data,null,2)); }

function savePlan(plan) {
	const all = _read();
	const id = 'plan_' + Date.now();
	all.push({ id, ...plan });
	_write(all);
	return id;
}
function listPlans(){ return _read(); }
module.exports = { savePlan, listPlans };