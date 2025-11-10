import React, { useRef } from 'react';
const FALLBACK_IMG = 'https://via.placeholder.com/400x200?text=No+image';

export default function ItineraryView({ payload, onSave }) {
  const { itinerary, context } = payload || {};
  // Aggregate stays from context.assets.stays -> flat array
  const stays = [];
  (context?.assets?.stays || []).forEach(group => {
    if (Array.isArray(group.items)) stays.push(...group.items);
  });

  const carouselRef = useRef(null);
  function scrollBy(offset) {
    if (!carouselRef.current) return;
    carouselRef.current.scrollBy({ left: offset, behavior: 'smooth' });
  }

  return (
    <div>
      <h3>{itinerary?.title || 'Itinerary'}</h3>
      <div><strong>Travel estimate:</strong> {context?.travelEstimate?.duration?.text} / {context?.travelEstimate?.distance?.text}</div>

      {stays.length > 0 && (
        <div style={{marginTop:12, marginBottom:12}}>
          <div style={{display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:8}}>
            <strong>Stay shortlist</strong>
            <div>
              <button onClick={()=>scrollBy(-400)} style={{marginRight:8}}>◀</button>
              <button onClick={()=>scrollBy(400)}>▶</button>
            </div>
          </div>
          <div
            ref={carouselRef}
            style={{
              display:'flex',
              gap:12,
              overflowX:'auto',
              paddingBottom:8,
              scrollBehavior:'smooth'
            }}
          >
            {stays.map((h, idx) => (
              <div key={h.id || idx} style={{minWidth:260, maxWidth:260, border:'1px solid #eee', borderRadius:8, padding:10, background:'#fff'}}>
                <div style={{position:'relative',width:'100%',height:140,overflow:'hidden',borderRadius:6,marginBottom:8}}>
                  <img
                    src={h.thumbnail || FALLBACK_IMG}
                    alt={h.name}
                    style={{width:'100%',height:'100%',objectFit:'cover'}}
                    onError={(e)=>{ e.currentTarget.onerror = null; e.currentTarget.src = FALLBACK_IMG; }}
                  />
                  {/* price badge top-right */}
                  <div style={{
                    position:'absolute',
                    top:8,
                    right:8,
                    background:'#ffffffcc',
                    padding:'6px 8px',
                    borderRadius:6,
                    fontWeight:700,
                    fontSize:13,
                    color:'#111',
                    boxShadow:'0 1px 4px rgba(0,0,0,0.15)'
                  }}>
                    {h.price_display || '—'}
                  </div>
                </div>
                <div style={{fontWeight:600, minHeight:40}}>{h.name}</div>
                <div style={{fontSize:12,color:'#666',marginBottom:6}}>{h.address}</div>
                <div style={{display:'flex',justifyContent:'space-between',alignItems:'center', marginTop:6}}>
                  <div style={{fontSize:14,fontWeight:700,color:'#1a73e8'}}>{h.price_display || 'Price unavailable'}</div>
                  <div style={{fontSize:12,color:'#555'}}>{h.rating ? `★ ${h.rating}` : ''}</div>
                </div>
                <div style={{marginTop:8, display:'flex', gap:8}}>
                  <button style={{flex:1}}>Select</button>
                  <a style={{flex:1,textDecoration:'none'}} href={h.raw && (h.raw.url || h.raw.booking_link) ? (h.raw.url || h.raw.booking_link) : '#'}><button style={{flex:1}}>Book</button></a>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {(itinerary?.days || []).map(d=>(
        <div key={d.date} style={{border:'1px solid #ccc',padding:8,margin:8}}>
          <div><strong>{d.date}</strong></div>
          {d.slots.map((s,idx)=>(<div key={idx}>{s.time} - {s.type} - {s.name} {s.address?`(${s.address})`:''} {s.estimated_cost?`- ${s.estimated_cost}`:''}</div>))}
        </div>
      ))}

      <div style={{marginTop:12}}>
        <button onClick={onSave}>Save Itinerary</button>
        <button onClick={()=>window.location.reload()} style={{marginLeft:8}}>Regenerate</button>
      </div>
    </div>
  );
}
