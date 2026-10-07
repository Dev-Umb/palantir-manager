## 1. Diagnose and design

- [x] 1.1 Measure production controller/SQL time read-only and verify the deployed configuration.
- [x] 1.2 Review narrow scope, identity compatibility, preserved fields and rollback design against the user's backend optimization request.
- [x] 1.3 Run strict OpenSpec validation before implementation.

## 2. Implementation and verification

- [x] 2.1 Prepare the one-line Palantir gzip MIME correction and review the exact diff.
- [x] 2.2 Test actual Nginx compression negotiation, decoded equality, small-body behavior and adjacent assets on an isolated listener.
- [x] 2.3 Run the local quality gate and preserve its actual result.
- [x] 2.4 Back up and drift-check the production snippet, validate, reload, or restore on failure.
- [x] 2.5 Verify live authenticated gzip/identity equivalence, response times and existing Android login/list/detail paths.
- [x] 2.6 Revalidate OpenSpec and record measured results and remaining limitations.

## Measured evidence — 2026-09-11

- Production-only change: `/etc/nginx/snippets/palantir-assets.conf` adds `application/json` to existing gzip_types. Backup: `/var/backups/palantir-assets.20260911T093113Z.conf`. After SHA-256: `f0f29821afd908e8e26f57f52cf2f4442bb0bcfd8549d40e4f20eef467be46fa`. Nginx syntax/active checks passed; PHP controllers and relations hashes unchanged.
- Isolated native Nginx PHPUnit: 1 test / 39 assertions passed on PHP 8.4.23. Local machine lacks Nginx: this integration case skips locally, rather than claiming it ran there.
- Local quality gate: 123 application tests / 1203 assertions passed plus the explicit native-test skip; 139 frontend tests passed, production build passed, 19 OpenSpec changes passed. Telemetry network flush failed but strict validation itself had zero failures.
- Same-session, alternating identity/gzip production test: 7 endpoints × 6 rounds × 2 encodings = 84 HTTP 200 samples; 42 decoded-data comparisons passed after removing only generated cockpit as_of timestamps. Separate gzip;q=0 identity check passed.
- Median identity → gzip ms: home 1147 → 304; project list 973 → 317; customer list 1073 → 165; project editor 697 → 134. Small detail responses show no uniform median improvement (project 96 → 112 ms; customer 97 → 105 ms). Gzip project-list worst sample remains 1483 ms; no fixed latency guarantee.
- Payload bytes: home 212892 → about 25617; project list 159777 → 23562; customer list 77913 → 14885. Compression level remains existing default, so these differ from the earlier level-5 size estimate.
- Same unmodified APK 1.0.3+5, 1080×2340/420 dpi: project list about 1.33 s, detail 0.35 s, return list 2.22 s, login to populated home 2.40 s (single video samples). Prior samples 5.40/1.12–1.40/>7.8/10.93 s are different-time observations, not a controlled UI A/B distribution. The first three new videos overlapped normal sequential HTTP probes; login was after them.
- Redis not added: measured SQL totaled ~14–21 ms; compression addresses the dominant demonstrated payload cost without stale data or invalidation changes. Client repeat-fetch behavior is still present and remains a separate improvement opportunity.
