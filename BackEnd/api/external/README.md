# Booking.com RapidAPI Proxy

The `booking_proxy.php` endpoint provides a thin wrapper around the RapidAPI `booking-com15`
interfaces so the frontend can request hotel metadata without exposing the RapidAPI key.

## Configure

Set the environment variable `RAPIDAPI_BOOKING_KEY` (or `BOOKING_RAPIDAPI_KEY`) before serving the backend, e.g.

```powershell
$env:RAPIDAPI_BOOKING_KEY = 'your-rapidapi-key'
php -S localhost:8000 -t BackEnd/public
```

## Usage

Perform a GET request against `/api/external/booking_proxy.php` with a `resource` parameter and the query parameters expected by the upstream Booking.com endpoint.

Supported `resource` values:

- `destinations` → `/api/v1/hotels/searchDestination`
- `hotels` → `/api/v1/hotels/searchHotels`
- `hotels-by-coordinates` → `/api/v1/hotels/searchHotelsByCoordinates`
- `hotel-details` → `/api/v1/hotels/getHotelDetails`
- `hotel-photos` → `/api/v1/hotels/getHotelPhotos`
- `hotel-description` → `/api/v1/hotels/getDescriptionAndInfo`

Example (search destinations for Kuala Lumpur):

```
GET /api/external/booking_proxy.php?resource=destinations&query=kuala%20lumpur
```

The proxy returns JSON with your selected resource, the upstream HTTP status code, and either `data` (success) or `details` (error).

## TripAdvisor proxy

`tripadvisor_proxy.php` targets the RapidAPI `tripadvisor16` host. It shares the same RapidAPI key lookup but also looks for `RAPIDAPI_TRIPADVISOR_KEY`.

Example (search hotels by location):

```
GET /api/external/tripadvisor_proxy.php?resource=search-hotels&location_id=275466&check_in=2025-11-01&check_out=2025-11-02&adults=2
```

Supported resources include:

- `search-location` → `/api/v1/hotels/searchLocation`
- `search-hotels` → `/api/v1/hotels/searchHotels`
- `search-hotels-by-location` → `/api/v1/hotels/searchHotelsByLocation`
- `hotel-details` → `/api/v1/hotels/getHotelDetails`
- `hotels-filter` → `/api/v1/hotels/getHotelsFilter`
- `restaurants-search` → `/api/v1/restaurants/searchRestaurants`
- `restaurants-details` → `/api/v1/restaurants/getRestaurantDetails`
- `attractions-search` → `/api/v1/attractions/searchAttractions`
- `attractions-details` → `/api/v1/attractions/getAttractionDetails`

The response shape mirrors the Booking proxy: `resource`, `upstreamStatus`, and either `data` or `details`.
