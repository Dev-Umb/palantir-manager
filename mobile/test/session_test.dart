import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/session_store.dart';

class MemorySessionStore implements SessionStore {
  String? value;
  bool failRead = false, failWrite = false, failClear = false;
  final writes = <String>[];
  @override
  Future<String?> read() async {
    if (failRead) throw StateError('unavailable');
    return value;
  }

  @override
  Future<void> write(String value) async {
    if (failWrite) throw StateError('unavailable');
    writes.add(value);
    this.value = value;
  }

  @override
  Future<void> clear() async {
    if (failClear) throw StateError('unavailable');
    value = null;
  }
}

Map<String, dynamic> sessionPage({bool guest = false}) => {
  'component': guest ? 'Auth/Login' : 'Settings/Index',
  'version': 'v1',
  'props': {
    'auth': {
      'user': guest ? null : {'id': 1, 'name': 'fixture'},
      'permissions': guest ? [] : ['object.project.view'],
      'roles': [],
    },
    'nav': [],
    'errors': {},
  },
};

void main() {
  late HttpServer server;
  late MemorySessionStore store;
  late PalantirApi api;
  final clients = <PalantirApi>[];
  late String origin;
  late Future<void> Function(HttpRequest) handler;
  Map<String, dynamic>? loginBody;
  var reads = 0;
  PalantirApi client() {
    final result = PalantirApi(origin, sessionStore: store);
    clients.add(result);
    return result;
  }

  void saved(List<Cookie> cookies, {String? address}) {
    store.value = jsonEncode({
      'origin': address ?? origin,
      'cookies': cookies.map((c) => c.toString()).toList(),
    });
  }

  setUp(() async {
    server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    origin = 'http://127.0.0.1:${server.port}';
    store = MemorySessionStore();
    api = client();
    loginBody = null;
    reads = 0;
    handler = (request) async {
      if (request.uri.path == '/login' && request.method == 'POST') {
        loginBody = jsonDecode(await utf8.decoder.bind(request).join());
        request.response.cookies.add(Cookie('session', 'signed-in'));
        request.response.cookies.add(Cookie('XSRF-TOKEN', 'csrf%3D'));
        if (loginBody!['remember'] == true) {
          request.response.cookies.add(
            Cookie('remember_web', 'recaller')..maxAge = 3600,
          );
        }
        request.response.statusCode = 302;
        request.response.headers.set('Location', '/settings');
      } else {
        request.response.write(
          jsonEncode(sessionPage(guest: request.uri.path == '/login')),
        );
      }
      await request.response.close();
    };
    server.listen((request) async {
      reads++;
      await handler(request);
    });
  });
  tearDown(() async {
    for (final client in clients) {
      client.dispose();
    }
    clients.clear();
    await server.close(force: true);
  });

  test('remembered login survives a new client and revalidates permissions without a password', () async {
    await api.login('a@example.test', 'not-persisted');
    expect(loginBody!['remember'], true);
    expect(store.value, isNot(contains('not-persisted')));
    expect(store.value, isNot(contains('permissions')));
    expect(store.value, isNot(contains('a@example.test')));
    final next = client();
    expect(next.user, isNull);
    handler = (request) async {
      expect(request.uri.path, '/settings');
      expect(request.method, 'GET');
      expect(request.headers.value('X-XSRF-TOKEN'), 'csrf=');
      expect(
        request.cookies.any(
          (c) => c.name == 'session' && c.value == 'signed-in',
        ),
        true,
      );
      final page = sessionPage();
      (page['props'] as Map)['auth']['permissions'] = [];
      request.response.write(jsonEncode(page));
      await request.response.close();
    };
    expect(await next.restoreSession(), true);
    expect(next.user?['id'], 1);
    expect(next.can('object.project.view'), false);
  });

  test('disabled remember clears old storage and stays memory-only', () async {
    saved([Cookie('old', 'account')]);
    await api.login('a@example.test', 'fixture', remember: false);
    expect(loginBody!['remember'], false);
    expect(api.user, isNotNull);
    expect(store.value, isNull);
    final before = reads;
    expect(await client().restoreSession(), false);
    expect(reads, before);
  });

  test(
    'expired session is omitted while remember cookie renews session and CSRF',
    () async {
      saved([
        Cookie('session', 'expired')..expires = DateTime.utc(2000),
        Cookie('remember_web', 'recaller')
          ..expires = DateTime.now().toUtc().add(const Duration(days: 30)),
      ]);
      handler = (request) async {
        expect(request.cookies.map((c) => c.name), ['remember_web']);
        request.response.cookies.add(
          Cookie('session', 'renewed')..maxAge = 3600,
        );
        request.response.cookies.add(Cookie('XSRF-TOKEN', 'new%3D'));
        request.response.write(jsonEncode(sessionPage()));
        await request.response.close();
      };
      expect(await api.restoreSession(), true);
      expect(store.value, contains('renewed'));
      expect(store.value, isNot(contains('Max-Age')));
      expect(store.value, isNot(contains('expired')));
    },
  );

  for (final status in [401, 419, 302]) {
    test(
      'rejected session $status clears memory and encrypted snapshot',
      () async {
        saved([Cookie('session', 'invalid')]);
        handler = (request) async {
          request.response.statusCode = status;
          if (status == 302) request.response.headers.set('Location', '/login');
          request.response.write('{}');
          await request.response.close();
        };
        expect(await api.restoreSession(), false);
        expect(api.user, isNull);
        expect(store.value, isNull);
      },
    );
  }

  test('server failure retains saved session for retry without exposing business state', () async {
    saved([Cookie('session', 'retry')]);
    final original = store.value;
    handler = (request) async {
      request.response.statusCode = 503;
      request.response.write('{}');
      await request.response.close();
    };
    await expectLater(api.restoreSession(), throwsA(isA<ApiFailure>()));
    expect(api.user, isNull);
    expect(store.value, original);
    handler = (request) async {
      request.response.write(jsonEncode(sessionPage()));
      await request.response.close();
    };
    expect(await api.restoreSession(), true);
  });

  test('wrong origin, corrupt record and fully expired cookies never make an HTTP request', () async {
    for (final value in [
      jsonEncode({
        'origin': 'https://other.example',
        'cookies': ['session=secret'],
      }),
      'corrupt',
      jsonEncode({
        'origin': origin,
        'cookies': ['session=expired; Expires=Sat, 01 Jan 2000 00:00:00 GMT'],
      }),
    ]) {
      store.value = value;
      expect(await api.restoreSession(), false);
      expect(store.value, isNull);
    }
    expect(reads, 0);
  });

  test(
    'wrong password never persists guest cookies or prior account',
    () async {
      saved([Cookie('old', 'account')]);
      handler = (request) async {
        request.response.cookies.add(Cookie('session', 'guest'));
        request.response.write(jsonEncode(sessionPage(guest: true)));
        await request.response.close();
      };
      await expectLater(
        api.login('a@example.test', 'wrong'),
        throwsA(isA<ApiFailure>()),
      );
      expect(store.value, isNull);
      expect(api.user, isNull);
      expect(api.authenticating, false);
    },
  );

  test(
    'storage failures do not silently claim successful remembered login',
    () async {
      store.failRead = true;
      await expectLater(api.restoreSession(), throwsA(isA<ApiFailure>()));
      expect(reads, 0);
      store.failRead = false;
      store.failWrite = true;
      await expectLater(
        api.login('a@example.test', 'fixture'),
        throwsA(isA<ApiFailure>()),
      );
      expect(api.user, isNull);
      expect(store.value, isNull);
    },
  );

  for (final offline in [false, true]) {
    test(
      'logout clears saved login even with server unavailable=$offline',
      () async {
        await api.login('a@example.test', 'fixture');
        api.assistantState['draft'] = 'private';
        handler = (request) async {
          expect(request.uri.path, '/logout');
          request.response.statusCode = offline ? 503 : 204;
          if (offline) request.response.write('{}');
          await request.response.close();
        };
        if (offline) {
          await expectLater(api.logout(), throwsA(isA<ApiFailure>()));
        } else {
          await api.logout();
        }
        expect(api.user, isNull);
        expect(api.assistantState, isEmpty);
        expect(store.value, isNull);
        expect(await client().restoreSession(), false);
      },
    );
  }

  test('late response cannot resurrect a cleared session', () async {
    await api.login('a@example.test', 'fixture');
    final arrived = Completer<void>();
    final release = Completer<void>();
    handler = (request) async {
      arrived.complete();
      await release.future;
      request.response.cookies.add(Cookie('session', 'late'));
      request.response.write(jsonEncode(sessionPage()));
      await request.response.close();
    };
    final pending = api.page('/settings');
    final checked = expectLater(pending, throwsA(isA<ApiFailure>()));
    await arrived.future;
    await api.clearSession();
    release.complete();
    await checked;
    expect(api.user, isNull);
    expect(store.value, isNull);
  });

  test(
    'rotation and deletion are persisted for JSON, upload and download',
    () async {
      await api.login('a@example.test', 'fixture');
      var counter = 0;
      handler = (request) async {
        counter++;
        await request.drain<void>();
        request.response.cookies.add(Cookie('session', 'rotation-$counter'));
        request.response.cookies.add(Cookie('remember_web', '')..maxAge = 0);
        request.response.write('{}');
        await request.response.close();
      };
      await api.send('/json', {});
      expect(store.value, contains('rotation-1'));
      expect(store.value, isNot(contains('remember_web')));
      await api.multipart('/upload', {});
      expect(store.value, contains('rotation-2'));
      await api.download('/file');
      expect(store.value, contains('rotation-3'));
    },
  );

  test(
    'failed local removal is visible and retry clears saved authentication',
    () async {
      await api.login('a@example.test', 'fixture');
      store.failClear = true;
      await expectLater(api.clearSession(), throwsA(isA<ApiFailure>()));
      expect(api.sessionClearFailed, true);
      expect(api.user, isNull);
      store.failClear = false;
      await api.clearSession();
      expect(api.sessionClearFailed, false);
      expect(store.value, isNull);
    },
  );

  test(
    'absolute origin redirect without slash retains root cookies and CSRF',
    () async {
      handler = (request) async {
        if (request.method == 'POST') {
          request.response.cookies.add(
            Cookie('session', 'authenticated')..path = '/',
          );
          request.response.cookies.add(
            Cookie('XSRF-TOKEN', 'root%3D')..path = '/',
          );
          request.response.statusCode = 302;
          request.response.headers.set('Location', origin);
        } else if (request.uri.path == '/login') {
          request.response.write(jsonEncode(sessionPage(guest: true)));
        } else {
          expect(request.uri.path, '/');
          expect(
            request.cookies.any(
              (c) => c.name == 'session' && c.value == 'authenticated',
            ),
            true,
          );
          expect(request.headers.value('X-XSRF-TOKEN'), 'root=');
          request.response.write(jsonEncode(sessionPage()));
        }
        await request.response.close();
      };
      await api.login('a@example.test', 'fixture');
      expect(api.user?['id'], 1);
      expect(store.value, contains('authenticated'));
    },
  );

  test('Android bridge only transports the encrypted-store protocol', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    final calls = <String>[];
    const channel = MethodChannel('palantir/android');
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          calls.add(call.method);
          if (call.method == 'writeSession') {
            expect(call.arguments, {'value': 'fixture-cookie-json'});
          }
          return call.method == 'readSession' ? 'fixture-cookie-json' : null;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null),
    );
    final native = AndroidSessionStore();
    await native.write('fixture-cookie-json');
    expect(await native.read(), 'fixture-cookie-json');
    await native.clear();
    expect(calls, ['writeSession', 'readSession', 'clearSession']);
  });
}
