import 'package:flutter/foundation.dart';

const productionApiUrl = 'https://palantir.umb.ink';
const localApiUrl = 'http://10.0.2.2:8765';

String resolveApiUrl({
  bool release = kReleaseMode,
  String override = const String.fromEnvironment('API_BASE_URL'),
}) {
  final address = override.isEmpty
      ? (release ? productionApiUrl : localApiUrl)
      : override;
  if (release && address != productionApiUrl) {
    throw StateError('正式版本必须连接已确认的生产 HTTPS 服务');
  }
  return address;
}
