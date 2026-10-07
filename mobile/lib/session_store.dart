import 'package:flutter/services.dart';

abstract class SessionStore {
  Future<String?> read();
  Future<void> write(String value);
  Future<void> clear();
}

class AndroidSessionStore implements SessionStore {
  static const _channel = MethodChannel('palantir/android');

  @override
  Future<String?> read() => _channel.invokeMethod<String>('readSession');

  @override
  Future<void> write(String value) =>
      _channel.invokeMethod<void>('writeSession', {'value': value});

  @override
  Future<void> clear() => _channel.invokeMethod<void>('clearSession');
}
