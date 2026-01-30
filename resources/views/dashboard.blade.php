@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

<div class="relative w-full h-screen overflow-hidden">
    <x-maps-google
        :centerPoint="['lat' => 52.16, 'long' => 5]"
        :zoomLevel="6"
        :mapType="'terrain'"
        :markers="[]"
        :fitToBounds="false"
    ></x-maps-google>
</div>

<div id="flight-popup" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9999] flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-md w-full max-h-[90vh] overflow-hidden relative">
        <button onclick="closePopup()" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center text-gray-500 hover:text-gray-900 z-10 bg-white rounded-full shadow">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>

        <div id="flight-details-content" class="overflow-y-auto max-h-[90vh]">
        </div>
    </div>
</div>

<script>
    let map = null;
    let theme;
    let clusterer = null;
    let aircraftMarkers = {};
    let selectedFlightId = null;
    const ANIM_DURATION_MS = 4500;
    const HEADING_CHANGE_THRESHOLD = 8;
    let iconCache = {};

    function getPlaneIcon(heading, isSelected = false) {
        const key = (isSelected ? 's' : '') + Math.round(heading || 0);
        if (iconCache[key]) return iconCache[key];
        const size = isSelected ? 40 : 30;
        const color = isSelected ? '#FFD700' : '#4285F4';
        const icon = {
            url: `data:image/svg+xml,${encodeURIComponent(`
                <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24">
                    <g transform="rotate(${heading - 90 || 0}, 12, 12)">
                        <path fill="${color}" stroke="#ffffff" stroke-width="0.5" 
                              d="M16 10h4a2 2 0 0 1 0 4h-4l-4 7h-3l2 -7h-4l-2 2h-3l2 -4l-2 -4h3l2 2h4l-2 -7h3l4 7"/>
                    </g>
                </svg>
            `)}`,
            scaledSize: new google.maps.Size(size, size),
            anchor: new google.maps.Point(size/2, size/2)
        };
        iconCache[key] = icon;
        return icon;
    }

    function lerp(a, b, t) {
        return a + (b - a) * Math.min(1, Math.max(0, t));
    }

    function animateMarkers() {
        if (!window.flightMap) return;
        const now = Date.now();
        Object.keys(aircraftMarkers).forEach(function(id) {
            const m = aircraftMarkers[id];
            if (!m.targetPos || !m.animationStart) return;
            const elapsed = now - m.animationStartTime;
            const t = elapsed / ANIM_DURATION_MS;
            if (t >= 1) {
                m.setPosition(m.targetPos);
                m.animationStart = null;
                m.targetPos = null;
                return;
            }
            const lat = lerp(m.animationStart.lat, m.targetPos.lat, t);
            const lng = lerp(m.animationStart.lng, m.targetPos.lng, t);
            m.setPosition({ lat, lng });
        });
    }
    
    function closePopup() {
        const popup = document.getElementById('flight-popup');
        popup.classList.add('hidden');
        
        if (selectedFlightId && aircraftMarkers[selectedFlightId]) {
            const marker = aircraftMarkers[selectedFlightId];
            const heading = marker.flightData[10] || 0;
            marker.setIcon(getPlaneIcon(heading, false));
        }
        selectedFlightId = null;
    }
    
    function showFlightDetails(flight, id) {
        const previouslySelected = selectedFlightId;
        selectedFlightId = id;
        
        const callsign = flight[1]?.trim() || 'Unknown';
        const originCountry = flight[2] || 'Unknown';
        const longitude = flight[5]?.toFixed(6) || 'N/A';
        const latitude = flight[6]?.toFixed(6) || 'N/A';
        const onGround = flight[8] || false;
        const icao24 = flight[0] || 'N/A';
        const altitude = flight[7] ? Math.round(flight[7]) : 0;
        const speed = flight[9] ? Math.round(flight[9] * 3.6) : 0;
        const heading = flight[10] ? Math.round(flight[10]) : 0;
        const verticalRate = flight[11] ? flight[11].toFixed(1) : '0.0';
        const lastContact = flight[4] ? Math.round((Date.now()/1000 - flight[4]) / 60) : "Unknown";
        
        const popup = document.getElementById('flight-popup');
        const detailsContent = document.getElementById('flight-details-content');
        
        detailsContent.innerHTML = `
            <div class="bg-gray-900 text-white p-6 rounded-t-lg">
                <div class="text-sm text-gray-400 mb-1">
                    ${onGround ? '🛬 On Ground' : '✈️ Airborne'} • ${originCountry}
                </div>
                <h1 class="text-3xl font-bold mb-1">${callsign}</h1>
                <p class="text-sm text-gray-400 font-mono">${icao24.toUpperCase()}</p>
            </div>
            
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-3">Flight Data</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-600 mb-1">Altitude</div>
                            <div class="text-xl font-bold text-gray-900">${altitude.toLocaleString()} <span class="text-sm font-normal text-gray-500">m</span></div>
                        </div>
                        
                        <div>
                            <div class="text-sm text-gray-600 mb-1">Speed</div>
                            <div class="text-xl font-bold text-gray-900">${speed} <span class="text-sm font-normal text-gray-500">km/h</span></div>
                        </div>
                        
                        <div>
                            <div class="text-sm text-gray-600 mb-1">Direction</div>
                            <div class="text-xl font-bold text-gray-900">${heading}°</div>
                        </div>
                        
                        <div>
                            <div class="text-sm text-gray-600 mb-1">Vertical Rate</div>
                            <div class="text-xl font-bold text-gray-900">${verticalRate} <span class="text-sm font-normal text-gray-500">m/s</span></div>
                        </div>

                        <div class="col-span-2">
                            <div class="text-sm text-gray-600 mb-1">Last Contact</div>
                            <div class="text-xl font-bold text-gray-900">${lastContact} <span class="text-sm font-normal text-gray-500">min ago</span></div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-3">Position</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Latitude</span>
                            <span class="text-sm font-mono text-gray-900">${latitude}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Longitude</span>
                            <span class="text-sm font-mono text-gray-900">${longitude}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        popup.classList.remove('hidden');
        if (previouslySelected && aircraftMarkers[previouslySelected]) {
            const prevMarker = aircraftMarkers[previouslySelected];
            const prevHeading = prevMarker.flightData[10] || 0;
            prevMarker.setIcon(getPlaneIcon(prevHeading, false));
        }
    }
    
    async function getdata(){
        const response = await fetch("https://opensky-network.org/api/states/all");
        const data = await response.json();
        return data;
    }

    async function updateFlightMarkers() {
        if (!window.flightMap) {
            return;
        }

        try {
            const data = await getdata();
            const flights = data.states || [];
            if (flights.length === 0) return;
            const currentAircraft = new Set();
            
            flights.forEach(function(flight) {
                if (flight[5] && flight[6]) {
                    const id = (flight[0] || '').toString().toLowerCase();
                    if (!id) return;
                    const callsign = flight[1];
                    const lat = flight[6];
                    const lng = flight[5];
                    const heading = flight[10] || 0;
                    const altitude = flight[7];
                    
                    currentAircraft.add(id);
                    
                    if (!aircraftMarkers[id]) {
                        const marker = new google.maps.Marker({
                            position: { lat, lng },
                            map: window.flightMap,
                            icon: getPlaneIcon(heading, false),
                            title: `${callsign?.trim() || id}\nAltitude: ${Math.round(altitude)}m`
                        });
                        
                        marker.flightData = flight;
                        
                        marker.addListener('click', function(e) {
                            if (e.domEvent) e.domEvent.stopPropagation();
                            selectedFlightId = id;
                            showFlightDetails(this.flightData, id);
                        });
                        
                        aircraftMarkers[id] = marker;
                        
                    } else {
                        const marker = aircraftMarkers[id];
                        const prevHeading = (marker.flightData && marker.flightData[10] != null) ? marker.flightData[10] : null;
                        const pos = marker.getPosition();
                        marker.animationStart = pos ? { lat: pos.lat(), lng: pos.lng() } : { lat, lng };
                        marker.targetPos = { lat, lng };
                        marker.animationStartTime = Date.now();

                        if (heading != null && prevHeading != null && Math.abs(heading - prevHeading) >= HEADING_CHANGE_THRESHOLD) {
                            marker.setIcon(getPlaneIcon(heading, false));
                        } else if (heading != null && prevHeading == null) {
                            marker.setIcon(getPlaneIcon(heading, false));
                        }

                        marker.setTitle(`${callsign?.trim() || id}\nAltitude: ${Math.round(altitude)}m`);
                        marker.flightData = flight;
                    }
                }
            });  
            const toRemove = [];
            Object.keys(aircraftMarkers).forEach(id => {
                if (!currentAircraft.has(id)) {
                    if (id === selectedFlightId) return;
                    const m = aircraftMarkers[id];
                    m.missingCount = (m.missingCount || 0) + 1;
                    if (m.missingCount >= 3) toRemove.push(id);
                }
            });
            toRemove.forEach(id => {
                aircraftMarkers[id].setMap(null);
                delete aircraftMarkers[id];
            });
            Object.keys(aircraftMarkers).forEach(id => {
                if (currentAircraft.has(id)) aircraftMarkers[id].missingCount = 0;
            });
            
        } catch (error) {
        }
        applyFilterToMarkers();
    }

    function matchesFlight(query, flightData) {
        if (!query || !flightData) return true;
        const q = query.trim().toLowerCase();
        if (!q) return true;
        const callsign = (flightData[1] || '').toString().toLowerCase();
        const country = (flightData[2] || '').toString().toLowerCase();
        return callsign.includes(q) || country.includes(q);
    }

    function applyFilterToMarkers() {
        const input = document.getElementById('plane-search');
        const query = input ? input.value.trim().toLowerCase() : '';
        Object.keys(aircraftMarkers).forEach(function(id) {
            const marker = aircraftMarkers[id];
            const show = !query || matchesFlight(query, marker.flightData);
            marker.setVisible(show);
        });
    }

    function waitForMap() {
        if (window.flightMap) {
            updateFlightMarkers();
            setInterval(updateFlightMarkers, 5000);
            setInterval(animateMarkers, 80);
        } else {
            setTimeout(waitForMap, 100);
        }
    }
    window.addEventListener('load', function() {
        setTimeout(waitForMap, 500);
        var form = document.getElementById('plane-search-form');
        var searchInput = document.getElementById('plane-search');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                applyFilterToMarkers();
            });
        }
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                applyFilterToMarkers();
            });
        }
    });
    document.getElementById('flight-popup').addEventListener('click', function(e) {
        if (e.target === this) {
            closePopup();
        }
    });

    window.applyFilterToMarkers = applyFilterToMarkers;
    window.updateFlightMarkers = updateFlightMarkers;
</script>

@endsection