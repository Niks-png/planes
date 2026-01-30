<style>
    #{{ $mapId }} {
        height: {{ $attributes['style'] ?? '100vh' }};
        width: 100%;
    }
</style>

<div
    id="{{ $mapId }}"
    @if(isset($attributes['class']))
        class="{{ $attributes['class'] }}"
    @endif
></div>

<script
    src="https://maps.googleapis.com/maps/api/js?key={{ config('maps.google_maps.access_token') }}&callback=initMap{{ $mapId }}"
    async
    defer
></script>

<script>
    let map{{ $mapId }} = null;

    function initMap{{ $mapId }}() {
        map{{ $mapId }} = new google.maps.Map(
            document.getElementById('{{ $mapId }}'),
            {
                center: {
                    lat: {{ $centerPoint['lat'] ?? $centerPoint[0] }},
                    lng: {{ $centerPoint['long'] ?? $centerPoint[1] }}
                },
                zoom: {{ $zoomLevel }},
                mapTypeId: '{{ $mapType ?? 'roadmap' }}',

                disableDefaultUI: true,
                restriction: {
                    latLngBounds: {
                        north: 85,
                        south: -85,
                        east: 180,
                        west: -180
                    },
                    strictBounds: true
                }
            }
        );
        window.flightMap = map{{ $mapId }};
        function addInfoWindow(marker, message) {
            const infoWindow = new google.maps.InfoWindow({
                content: message
            });
            marker.addListener('click', () => {
                infoWindow.open(map{{ $mapId }}, marker);
            });
        }
        @if($fitToBounds || $centerToBoundsCenter)
        const bounds = new google.maps.LatLngBounds();
        @endif

        @foreach($markers as $marker)
            const marker{{ $loop->iteration }} = new google.maps.Marker({
                position: {
                    lat: {{ $marker['lat'] ?? $marker[0] }},
                    lng: {{ $marker['long'] ?? $marker[1] }}
                },
                map: map{{ $mapId }},
                @if(isset($marker['title']))
                title: "{{ $marker['title'] }}",
                @endif
                icon: "{{ $marker['icon'] ?? asset('icons/flight.png') }}"
            });

            @if(isset($marker['info']))
                addInfoWindow(marker{{ $loop->iteration }}, @json($marker['info']));
            @endif

            @if($fitToBounds || $centerToBoundsCenter)
                bounds.extend({
                    lat: {{ $marker['lat'] ?? $marker[0] }},
                    lng: {{ $marker['long'] ?? $marker[1] }}
                });
            @endif
        @endforeach

        @if($fitToBounds)
            map{{ $mapId }}.fitBounds(bounds);
        @endif

        @if($centerToBoundsCenter)
            map{{ $mapId }}.setCenter(bounds.getCenter());
        @endif
    }
</script>