import React, { useState } from 'react';

export default function PlannerForm({ onResult }){
  const [currentLocation, setCurrentLocation] = useState('');
  const [destination, setDestination] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [themes, setThemes] = useState([]);
  const options = ['Culture','Food','Relax','Nature','Adventure','Cityscape','Historical'];
  const [error, setError] = useState('');

  function toggleTheme(t){
    setThemes(prev => prev.includes(t) ? prev.filter(x=>x!==t) : [...prev,t]);
  }

  async function submit(){
    setError('');
    try {
      const body = {
        currentLocation: currentLocation || undefined,
        destination,
        dateRange: { start, end },
        preferences: {
          themes,
          groupSize: 2,
          travelPace: 'Moderate',
          accommodationStyle: 'Standard',
          budget: null
        }
      };

      const r = await fetch('http://localhost:4000/api/plan', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
      });

      const data = await r.json();
      if (data.error) {
        setError(data.error);
        return;
      }
      onResult(data);
    } catch (err) {
      setError('Failed to generate plan. Please try again.');
    }
  }

  return (
    <div style={{marginBottom:20}}>
      <div><label>Current location (optional): <input value={currentLocation} onChange={e=>setCurrentLocation(e.target.value)} placeholder="My location or leave blank" /></label></div>
      <div><label>Destination (city/landmark in Malaysia): <input value={destination} onChange={e=>setDestination(e.target.value)} /></label></div>
      <div><label>Start: <input type="date" value={start} onChange={e=>setStart(e.target.value)} /></label> <label>End: <input type="date" value={end} onChange={e=>setEnd(e.target.value)} /></label></div>
      <div>
        Themes:
        {options.map(o=>(
          <label key={o} style={{marginLeft:8}}>
            <input type="checkbox" checked={themes.includes(o)} onChange={()=>toggleTheme(o)} /> {o}
          </label>
        ))}
      </div>
      {error && (
        <div style={{color: 'red', marginTop: 10}}>
          Error: {error}
        </div>
      )}
      <button 
        onClick={submit} 
        disabled={!destination || !start || !end || themes.length === 0}
        style={{marginTop:10}}
      >
        Generate Plan
      </button>
      <div style={{fontSize: '0.9em', marginTop: 5, color: '#666'}}>
        Tip: Enter a major Malaysian city (e.g., Kuala Lumpur, Penang, Malacca) or well-known landmark
      </div>
    </div>
  );
}
