<?php

namespace Drupal\psp_request_service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Service-address lookup for the Book Online form (Google Places API New).
 *
 * The browser never talks to Google: the form calls this site's
 * /psp-address/* endpoints, which call Places with a server-side key (stored
 * encrypted via the Key module, restricted by server IP in Google Cloud), so
 * one key serves every site on the server and no Google script runs on the
 * visitor's device.
 *
 * Serviceability stays zip-based: every result carries its zip (or, for a
 * city, the service-area zips in that city from the zip_places list) and the
 * form's existing zip field/pattern decides in or out of area.
 */
class AddressLookup {

  const PLACES = 'https://places.googleapis.com/v1/';

  /**
   * Result kinds the suggestions are limited to (Places allows five).
   */
  const TYPES = ['street_address', 'premise', 'subpremise', 'locality', 'postal_code'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Suggestions for what the visitor has typed.
   *
   * @return array
   *   List of ['id', 'main', 'secondary', 'kind' (address|city|zip)].
   *
   * @throws \RuntimeException
   *   When the lookup service is unavailable (the form falls back to zip).
   */
  public function suggest(string $input, string $session): array {
    $input = trim($input);
    if ($this->stub()) {
      return $this->stubSuggest($input);
    }
    $body = [
      'input' => $input,
      'includedRegionCodes' => ['us'],
      'includedPrimaryTypes' => self::TYPES,
    ];
    if ($session !== '') {
      $body['sessionToken'] = $session;
    }
    if ($bias = $this->bias()) {
      $body['locationBias'] = ['circle' => $bias];
    }
    $data = $this->request('POST', 'places:autocomplete', ['json' => $body]);
    $suggestions = [];
    foreach ($data['suggestions'] ?? [] as $item) {
      $p = $item['placePrediction'] ?? NULL;
      if (!$p || empty($p['placeId'])) {
        continue;
      }
      $suggestions[] = [
        'id' => $p['placeId'],
        'main' => $p['structuredFormat']['mainText']['text'] ?? $p['text']['text'] ?? '',
        'secondary' => preg_replace('/, USA$/', '', $p['structuredFormat']['secondaryText']['text'] ?? ''),
        'kind' => $this->kind($p['types'] ?? []),
      ];
    }
    // Not cached: Places terms allow storing place IDs only.
    return $suggestions;
  }

  /**
   * The chosen suggestion, resolved to an address, a zip or a city.
   *
   * @return array
   *   ['kind', 'formatted', 'street', 'city', 'state', 'zip', 'zips'] where
   *   zips (cities only) lists the service-area zips in that city.
   *
   * @throws \RuntimeException
   */
  public function place(string $id, string $session): array {
    if ($this->stub()) {
      return $this->stubPlace($id);
    }
    if (!preg_match('/^[A-Za-z0-9_-]{10,300}$/', $id)) {
      throw new \InvalidArgumentException('Invalid place id.');
    }
    $query = $session !== '' ? ['sessionToken' => $session] : [];
    $data = $this->request('GET', 'places/' . $id, [
      'query' => $query,
      'headers' => ['X-Goog-FieldMask' => 'formattedAddress,addressComponents,types'],
    ]);
    $parts = [];
    foreach ($data['addressComponents'] ?? [] as $component) {
      foreach ($component['types'] ?? [] as $type) {
        $parts[$type] ??= $component['shortText'] ?? $component['longText'] ?? '';
      }
    }
    $street = trim(($parts['street_number'] ?? '') . ' ' . ($parts['route'] ?? ''));
    if (!empty($parts['subpremise'])) {
      $street .= ' #' . $parts['subpremise'];
    }
    $result = [
      'kind' => $this->kind($data['types'] ?? []),
      'formatted' => preg_replace('/, USA$/', '', $data['formattedAddress'] ?? ''),
      'street' => $street,
      'city' => $parts['locality'] ?? $parts['sublocality'] ?? $parts['postal_town'] ?? '',
      'state' => $parts['administrative_area_level_1'] ?? '',
      'zip' => $parts['postal_code'] ?? '',
      'zips' => [],
    ];
    if ($result['kind'] === 'city' || ($result['zip'] === '' && $result['street'] === '')) {
      $result['kind'] = 'city';
      $result['zips'] = $this->zipsForCity($result['city'], $result['state']);
    }
    return $result;
  }

  /**
   * Service-area zips (from zip_places) whose city matches.
   */
  public function zipsForCity(string $city, string $state): array {
    $zips = [];
    foreach ($this->zipPlaces() as $place) {
      if (strcasecmp($place['city'], $city) === 0 && ($state === '' || strcasecmp($place['state'], $state) === 0)) {
        $zips[] = $place['zip'];
      }
    }
    sort($zips);
    return $zips;
  }

  /**
   * Whether lookups are configured (a key, or stub mode for testing).
   */
  public function isAvailable(): bool {
    return $this->stub() || $this->apiKey() !== '';
  }

  /**
   * Fills zip_places (zip, city, state, lat, lng) for the service-area zips.
   *
   * Uses the free, keyless zippopotam.us lookup.
   *
   * @return array
   *   ['found' => int, 'missing' => string[]].
   */
  public function refreshZipPlaces(): array {
    $config = $this->configFactory->getEditable('psp_request_service.settings');
    $existing = [];
    foreach ((array) $config->get('zip_places') as $place) {
      $existing[$place['zip']] = $place;
    }
    $places = [];
    $missing = [];
    foreach ((array) $config->get('zips') as $zip) {
      if (!preg_match('/^\d{5}$/', (string) $zip)) {
        continue;
      }
      if (isset($existing[$zip])) {
        $places[] = $existing[$zip];
        continue;
      }
      try {
        $response = $this->httpClient->request('GET', 'https://api.zippopotam.us/us/' . $zip, ['timeout' => 10, 'http_errors' => FALSE]);
        $data = json_decode((string) $response->getBody(), TRUE);
        $place = $data['places'][0] ?? NULL;
        if ($response->getStatusCode() === 200 && $place) {
          $places[] = [
            'zip' => (string) $zip,
            'city' => (string) $place['place name'],
            'state' => (string) $place['state abbreviation'],
            'lat' => (float) $place['latitude'],
            'lng' => (float) $place['longitude'],
          ];
          continue;
        }
      }
      catch (\Throwable $e) {
        // Recorded as missing below.
      }
      $missing[] = (string) $zip;
    }
    $config->set('zip_places', $places)->save();
    return ['found' => count($places), 'missing' => $missing];
  }

  /**
   * The zip_places list.
   */
  public function zipPlaces(): array {
    return (array) $this->configFactory->get('psp_request_service.settings')->get('zip_places');
  }

  /**
   * A circle around the service area (centre of its zips, max 50 km).
   */
  protected function bias(): ?array {
    $places = array_filter($this->zipPlaces(), fn($p) => !empty($p['lat']) && !empty($p['lng']));
    if (!$places) {
      return NULL;
    }
    $lat = array_sum(array_column($places, 'lat')) / count($places);
    $lng = array_sum(array_column($places, 'lng')) / count($places);
    $radius = 0;
    foreach ($places as $p) {
      $radius = max($radius, $this->distance($lat, $lng, $p['lat'], $p['lng']));
    }
    return [
      'center' => ['latitude' => $lat, 'longitude' => $lng],
      'radius' => min(50000.0, max(5000.0, $radius + 5000)),
    ];
  }

  /**
   * Metres between two points.
   */
  protected function distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371000;
    $dlat = deg2rad($lat2 - $lat1);
    $dlng = deg2rad($lng2 - $lng1);
    $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
  }

  /**
   * Maps Places types to the form's kinds.
   */
  protected function kind(array $types): string {
    if (in_array('postal_code', $types, TRUE)) {
      return 'zip';
    }
    if (array_intersect(['locality', 'sublocality', 'postal_town', 'administrative_area_level_3'], $types)) {
      return 'city';
    }
    return 'address';
  }

  /**
   * Calls the Places API.
   *
   * @throws \RuntimeException
   */
  protected function request(string $method, string $path, array $options): array {
    $key = $this->apiKey();
    if ($key === '') {
      throw new \RuntimeException('No Places API key configured.');
    }
    $options['headers'] = ($options['headers'] ?? []) + ['X-Goog-Api-Key' => $key];
    $options += ['timeout' => 6, 'connect_timeout' => 3, 'http_errors' => FALSE];
    try {
      $response = $this->httpClient->request($method, self::PLACES . $path, $options);
    }
    catch (\Throwable $e) {
      $this->logger->error('Places API request failed: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('Lookup unavailable.', 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE) ?: [];
    if ($response->getStatusCode() !== 200) {
      $this->logger->error('Places API @code: @status @message', [
        '@code' => $response->getStatusCode(),
        '@status' => $data['error']['status'] ?? '',
        '@message' => $data['error']['message'] ?? '',
      ]);
      throw new \RuntimeException('Lookup unavailable.');
    }
    return $data;
  }

  /**
   * The decrypted API key, or ''.
   */
  protected function apiKey(): string {
    $id = (string) $this->configFactory->get('psp_request_service.settings')->get('address_key_id');
    if ($id === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($id);
    return $key ? trim((string) $key->getKeyValue()) : '';
  }

  /**
   * Whether stub mode (canned results, no API calls) is on.
   */
  protected function stub(): bool {
    return (bool) $this->configFactory->get('psp_request_service.settings')->get('address_stub');
  }

  /**
   * Canned suggestions for testing every path without API calls.
   *
   * "outage" fails like the service being down; "nowhere" finds nothing;
   * a known service-area city name gives that city; otherwise two addresses,
   * one in and one out of the area.
   */
  protected function stubSuggest(string $input): array {
    if (stripos($input, 'outage') !== FALSE) {
      throw new \RuntimeException('Stub outage.');
    }
    if (stripos($input, 'nowhere') !== FALSE) {
      return [];
    }
    foreach ($this->zipPlaces() as $place) {
      if (stripos($input, $place['city']) === 0) {
        return [['id' => 'stub-city-' . $place['city'] . '-' . $place['state'], 'main' => $place['city'], 'secondary' => $place['state'], 'kind' => 'city']];
      }
    }
    $zip = $this->zipPlaces()[0]['zip'] ?? '27617';
    return [
      ['id' => 'stub-address-' . $zip, 'main' => '110 ' . ucwords($input), 'secondary' => 'Raleigh, NC', 'kind' => 'address'],
      ['id' => 'stub-address-90210', 'main' => '1 ' . ucwords($input), 'secondary' => 'Beverly Hills, CA', 'kind' => 'address'],
    ];
  }

  /**
   * Canned place details for stubSuggest() ids.
   */
  protected function stubPlace(string $id): array {
    if (str_starts_with($id, 'stub-city-')) {
      [$city, $state] = explode('-', substr($id, 10), 2) + ['', ''];
      return ['kind' => 'city', 'formatted' => "$city, $state", 'street' => '', 'city' => $city, 'state' => $state, 'zip' => '', 'zips' => $this->zipsForCity($city, $state)];
    }
    $zip = substr($id, 13);
    return ['kind' => 'address', 'formatted' => "110 Test Street, Test City, NC $zip", 'street' => '110 Test Street', 'city' => 'Test City', 'state' => 'NC', 'zip' => $zip, 'zips' => []];
  }

}
