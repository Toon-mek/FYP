import React, { useState } from 'react';
import PlannerForm from './components/PlannerForm';
import ItineraryView from './components/ItineraryView';

export default function App(){
  const [result, setResult] = useState(null);
  return (
    <div style={{padding:20}}>
      <h2>AI Trip Planner (Malaysia)</h2>
      <PlannerForm onResult={setResult} />
      {result && <ItineraryView payload={result} onSave={() => {
        fetch('http://localhost:4000/api/save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ itinerary: result.itinerary, meta: result.context })});
        alert('Saved (local DB)');
      }} />}
    </div>
  );
}
