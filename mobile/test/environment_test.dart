import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/environment.dart';

void main() {
  test('release selects the production HTTPS origin', () {
    expect(resolveApiUrl(release: true), productionApiUrl);
    expect(
      resolveApiUrl(release: true, override: productionApiUrl),
      productionApiUrl,
    );
  });

  test('debug preserves the local emulator and explicit override', () {
    expect(resolveApiUrl(release: false), localApiUrl);
    expect(
      resolveApiUrl(release: false, override: productionApiUrl),
      productionApiUrl,
    );
  });

  test('release rejects local, insecure and unapproved destinations', () {
    for (final address in [
      localApiUrl,
      'http://palantir.umb.ink',
      'https://example.com',
      '$productionApiUrl.attacker.example',
    ]) {
      expect(
        () => resolveApiUrl(release: true, override: address),
        throwsStateError,
      );
    }
  });
}
