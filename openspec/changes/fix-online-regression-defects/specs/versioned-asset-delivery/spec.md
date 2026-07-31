## ADDED Requirements

### Requirement: Fingerprinted assets have compression and immutable cache policy

The repository MUST provide nginx configuration that enables gzip for CSS and JavaScript and adds a one-year immutable cache policy only to fingerprinted Vite assets under `/build/assets/`.

#### Scenario: Client accepts gzip

- **WHEN** nginx serves an eligible CSS or JavaScript asset to a client that accepts gzip
- **THEN** the response is gzip encoded

#### Scenario: Client requests a fingerprinted asset

- **WHEN** nginx serves `/build/assets/{fingerprinted-file}`
- **THEN** the response includes `Cache-Control: public, max-age=31536000, immutable`

### Requirement: Deployment does not silently rewrite nginx

The application deployment script MUST detect whether the reviewed asset snippet is active and MUST print installation guidance when it is absent without writing nginx configuration.

#### Scenario: Asset snippet is missing

- **WHEN** deployment inspects the confirmed nginx site and does not find the snippet
- **THEN** deployment reports the missing include and the required backup and `nginx -t` steps without changing `/etc/nginx`
