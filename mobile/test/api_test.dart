import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';

void main() {
  late HttpServer server;
  late PalantirApi api;
  setUp(() async {
    server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    api = PalantirApi('http://127.0.0.1:${server.port}');
  });
  tearDown(() async {
    api.dispose();
    await server.close(force: true);
  });
  Map<String, dynamic> webPage({
    bool signedIn = true,
    Map<String, dynamic> extra = const {},
  }) => {
    'component': signedIn ? 'Settings/Index' : 'Auth/Login',
    'version': 'v1',
    'url': signedIn ? '/settings' : '/login',
    'props': {
      'auth': {
        'user': signedIn ? {'id': 1} : null,
        'permissions': [],
        'roles': [
          {'label': '业务员'},
        ],
      },
      'nav': [
        {'key': 'hub', 'visible': true},
      ],
      'errors': {},
      ...extra,
    },
  };
  test('version conflict without header recovers initial HTML and retains same-origin cookies', () async {
    var requests = 0;
    server.listen((request) async {
      requests++;
      if (requests == 1) {
        request.response.statusCode = 409;
        request.response.headers.set('X-Inertia-Location', '/login');
        request.response.cookies.add(Cookie('session', 'local-fixture'));
      } else if (requests == 2) {
        expect(request.headers.value('X-Inertia'), isNull);
        expect(request.cookies.single.value, 'local-fixture');
        request.response.headers.contentType = ContentType.html;
        request.response.write(
          '<script type="application/json" data-page="app">${jsonEncode(webPage(signedIn: false))}</script>',
        );
      } else {
        expect(request.headers.value('X-Inertia'), 'true');
        expect(request.headers.value('X-Inertia-Version'), 'v1');
        expect(request.cookies.single.value, 'local-fixture');
        request.response.write(jsonEncode(webPage(signedIn: false)));
      }
      await request.response.close();
    });
    await api.bootstrap();
    expect(requests, 3);
    expect(api.user, isNull);
  });

  test('HTML version recovery refuses a foreign redirect', () async {
    var requests = 0;
    server.listen((request) async {
      requests++;
      request.response.statusCode = 409;
      request.response.headers.set(
        'X-Inertia-Location',
        'https://example.com/login',
      );
      await request.response.close();
    });
    await expectLater(api.bootstrap(), throwsA(isA<ApiFailure>()));
    expect(requests, 1);
  });

  test(
    'HTML version recovery rejects a page without valid bootstrap data',
    () async {
      var requests = 0;
      server.listen((request) async {
        requests++;
        if (requests == 1) {
          request.response.statusCode = 409;
          request.response.headers.set('X-Inertia-Location', '/login');
        } else {
          request.response.write('<html>Unavailable</html>');
        }
        await request.response.close();
      });
      await expectLater(api.bootstrap(), throwsA(isA<ApiFailure>()));
      expect(requests, 2);
    },
  );

  test('never forward cookies to another origin', () {
    expect(
      () => api.uri('https://example.com/attachment'),
      throwsA(isA<ApiFailure>()),
    );
    expect(
      () => api.uri('//example.com/attachment'),
      throwsA(isA<ApiFailure>()),
    );
    expect(() => PalantirApi('http://example.com'), throwsArgumentError);
  });
  for (final compressed in [false, true]) {
    test('detail preserves Unicode and values with gzip=$compressed', () async {
      server.listen((request) async {
        expect(request.headers.value('Accept-Encoding'), contains('gzip'));
        expect(
          request.headers.value('X-Inertia-Partial-Component'),
          'Ontology/Index',
        );
        final page = webPage(
          extra: {
            'currentObject': {'key': 'project', 'fields': []},
            'selectedRecord': {
              'id': 'p1',
              'title': '回归项目',
              'payload': {
                'unpaid_amount': -12.5,
                'paid_amount': 0,
                'remark': null,
              },
            },
            'can': {'update': false},
          },
        );
        page['component'] = 'Ontology/Index';
        final bytes = utf8.encode(jsonEncode(page));
        request.response.headers.contentType = ContentType.json;
        if (compressed) {
          request.response.headers.set(
            HttpHeaders.contentEncodingHeader,
            'gzip',
          );
        }
        request.response.cookies.add(Cookie('XSRF-TOKEN', 'updated%3D'));
        request.response.add(compressed ? gzip.encode(bytes) : bytes);
        await request.response.close();
      });
      final result = await api.recordPage('project', 'p1');
      expect(result['selectedRecord']['title'], '回归项目');
      expect(result['selectedRecord']['payload'], {
        'unpaid_amount': -12.5,
        'paid_amount': 0,
        'remark': null,
      });
      expect(result['can']['update'], false);
      expect(api.user?['id'], 1);
      expect(api.can('object.project.update'), false);
    });
  }
  test(
    'gzip validation and expired-session responses keep error semantics',
    () async {
      var expired = false;
      server.listen((request) async {
        request.response.statusCode = expired ? 401 : 422;
        request.response.headers.contentType = ContentType.json;
        request.response.headers.set(HttpHeaders.contentEncodingHeader, 'gzip');
        request.response.add(
          gzip.encode(
            utf8.encode(
              jsonEncode({
                'message': expired ? 'Unauthenticated.' : '项目名称不能为空',
                if (!expired)
                  'errors': {
                    'name': ['项目名称不能为空'],
                  },
              }),
            ),
          ),
        );
        await request.response.close();
      });
      api.user = {'id': 1};
      await expectLater(
        api.send('/records/p1', {}),
        throwsA(
          isA<ApiFailure>()
              .having((e) => e.status, 'status', 422)
              .having((e) => e.toString(), 'message', contains('项目名称不能为空')),
        ),
      );
      expect(api.user?['id'], 1);
      expired = true;
      await expectLater(
        api.page('/settings'),
        throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 401)),
      );
      expect(api.user, isNull);
    },
  );
  test(
    'existing login negotiates Inertia and rotates cookie CSRF for writes',
    () async {
      var negotiated = false;
      var loggedIn = false;
      server.listen((r) async {
        r.response.headers.contentType = ContentType.json;
        if (r.method == 'GET' && r.uri.path == '/login') {
          expect(r.headers.value('X-Inertia'), 'true');
          if (!negotiated) {
            negotiated = true;
            r.response.statusCode = 409;
            r.response.headers.set('X-Inertia-Location', '/login');
            r.response.headers.set('X-Inertia-Version', 'v1');
          } else {
            expect(r.headers.value('X-Inertia-Version'), 'v1');
            r.response.cookies.add(Cookie('session', 'fixture'));
            r.response.cookies.add(Cookie('XSRF-TOKEN', 'csrf%3D'));
            r.response.write(jsonEncode(webPage(signedIn: false)));
          }
        } else if (r.method == 'POST' && r.uri.path == '/login') {
          expect(
            r.cookies.firstWhere((c) => c.name == 'session').value,
            'fixture',
          );
          expect(r.headers.value('X-XSRF-TOKEN'), 'csrf=');
          expect(
            jsonDecode(await utf8.decoder.bind(r).join())['email'],
            'test@example.test',
          );
          loggedIn = true;
          r.response.cookies.add(Cookie('session', 'authenticated'));
          r.response.cookies.add(Cookie('XSRF-TOKEN', 'rotated%3D'));
          r.response.statusCode = 302;
          r.response.headers.set('Location', '/settings');
        } else if (r.method == 'GET') {
          expect(loggedIn, true);
          expect(
            r.cookies.firstWhere((c) => c.name == 'session').value,
            'authenticated',
          );
          r.response.write(jsonEncode(webPage()));
        } else {
          expect(r.headers.value('X-XSRF-TOKEN'), 'rotated=');
          r.response.write('{"saved":true}');
        }
        await r.response.close();
      });
      await api.login('test@example.test', 'fixture');
      expect(api.user?['id'], 1);
      expect(api.canProcurement, true);
      expect(api.canUpload, false);
      expect((await api.send('/records/a', {}))['saved'], true);
    },
  );
  test(
    'wrong password redirect reports web validation and stays logged out',
    () async {
      var attempted = false;
      server.listen((r) async {
        if (r.method == 'POST') {
          attempted = true;
          r.response.statusCode = 302;
          r.response.headers.set('Location', '/login');
        } else {
          r.response.write(
            jsonEncode(
              webPage(
                signedIn: false,
                extra: {
                  'errors': attempted ? {'email': '账号或密码不正确。'} : {},
                },
              ),
            ),
          );
        }
        await r.response.close();
      });
      await expectLater(
        api.login('a@example.test', 'fixture'),
        throwsA(
          isA<ApiFailure>().having((e) => e.message, 'error', '账号或密码不正确。'),
        ),
      );
      expect(api.user, isNull);
    },
  );
  test(
    'page props retain business fields and server upload permission',
    () async {
      server.listen((r) async {
        r.response.write(
          jsonEncode(
            webPage(
              extra: {
                'canUploadContracts': true,
                'selectedRecord': {
                  'id': 'p1',
                  'payload': {'unpaid_amount': -10},
                },
                'fields': [
                  {'key': 'unpaid_amount', 'readonly': true},
                ],
              },
            ),
          ),
        );
        await r.response.close();
      });
      final data = await api.page('/objects/project?record=p1');
      expect(data['selectedRecord']['payload']['unpaid_amount'], -10);
      expect(data['fields'].first['readonly'], true);
      expect(api.canUpload, true);
    },
  );
  test(
    'missing selected record is inaccessible rather than an empty editor',
    () async {
      server.listen((r) async {
        r.response.write(jsonEncode(webPage()));
        await r.response.close();
      });
      await expectLater(
        api.page('/objects/project?record=missing'),
        throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 404)),
      );
    },
  );
  test(
    'settings redirect validates returned page before reporting success',
    () async {
      server.listen((r) async {
        if (r.method == 'PUT') {
          r.response.statusCode = 303;
          r.response.headers.set('Location', '/settings');
        } else {
          r.response.write(
            jsonEncode(
              webPage(
                extra: {
                  'errors': {'email': '邮箱已使用'},
                },
              ),
            ),
          );
        }
        await r.response.close();
      });
      await expectLater(
        api.send('/settings/email', {}, method: 'PUT'),
        throwsA(isA<ApiFailure>().having((e) => e.message, 'error', '邮箱已使用')),
      );
    },
  );
  test(
    'foreign redirects and version locations are rejected without a request',
    () async {
      var count = 0;
      server.listen((r) async {
        count++;
        r.response.statusCode = r.uri.path == '/settings' ? 409 : 302;
        r.response.headers.set('Location', 'https://example.com/');
        r.response.headers.set('X-Inertia-Location', 'https://example.com/');
        r.response.headers.set('X-Inertia-Version', 'v2');
        await r.response.close();
      });
      await expectLater(api.page('/settings'), throwsA(isA<ApiFailure>()));
      await expectLater(api.send('/login', {}), throwsA(isA<ApiFailure>()));
      expect(count, 2);
    },
  );
  test('redirect loops are bounded', () async {
    var count = 0;
    server.listen((r) async {
      count++;
      r.response.statusCode = 302;
      r.response.headers.set('Location', '/settings');
      await r.response.close();
    });
    await expectLater(api.page('/settings'), throwsA(isA<ApiFailure>()));
    expect(count, 4);
  });
  test('logout uses existing web redirect and clears cached data', () async {
    api.user = {'id': 1};
    api.assistantState['draft'] = 'draft';
    server.listen((r) async {
      if (r.method == 'POST') {
        expect(r.uri.path, '/logout');
        r.response.statusCode = 302;
        r.response.headers.set('Location', '/login');
      } else {
        r.response.write(jsonEncode(webPage(signedIn: false)));
      }
      await r.response.close();
    });
    await api.logout();
    expect(api.user, isNull);
    expect(api.assistantState, isEmpty);
  });
  test('expired web login redirect clears authenticated state', () async {
    api.user = {'id': 1};
    server.listen((r) async {
      r.response.statusCode = 302;
      r.response.headers.set(
        'Location',
        'http://127.0.0.1:${server.port}/login',
      );
      await r.response.close();
    });
    await expectLater(
      api.get('/records/a'),
      throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 401)),
    );
    expect(api.user, isNull);
  });
  test('validation failures preserve server field details', () async {
    server.listen((r) async {
      r.response.statusCode = 422;
      r.response.write(
        jsonEncode({
          'errors': {
            'payload.name': ['项目名称必填'],
          },
        }),
      );
      await r.response.close();
    });
    await expectLater(
      api.send('/records/a', {}),
      throwsA(
        isA<ApiFailure>()
            .having((e) => e.message, 'message', '项目名称必填')
            .having((e) => e.errors, 'errors', contains('payload.name')),
      ),
    );
  });
  test('unexpected HTML success is not reported as a saved record', () async {
    server.listen((r) async {
      r.response.write('<html>Login</html>');
      await r.response.close();
    });
    await expectLater(api.send('/records/a', {}), throwsA(isA<ApiFailure>()));
  });
  test('record requests select fresh detail props and preserve version retry headers', () async {
    var attempts = 0;
    final selected = {
      'id': 'p1',
      'payload': {'unpaid_amount': 0},
      'contracts': [
        {
          'id': 'c1',
          'payload': {
            'attachments': [
              {'url': '/private/a.pdf'},
            ],
          },
        },
      ],
    };
    server.listen((r) async {
      attempts++;
      expect(r.uri.path, '/objects/project');
      expect(r.uri.queryParameters, {'record': 'p1', 'per_page': '1'});
      expect(r.headers.value('X-Inertia-Partial-Component'), 'Ontology/Index');
      final keys = r.headers.value('X-Inertia-Partial-Data')!.split(',');
      expect(
        keys,
        containsAll([
          'auth',
          'nav',
          'errors',
          'currentObject',
          'selectedRecord',
          'can',
        ]),
      );
      expect(keys, isNot(contains('records')));
      expect(keys, isNot(contains('relationOptions')));
      if (attempts == 1) {
        r.response.statusCode = 409;
        r.response.headers.set('X-Inertia-Version', 'updated');
        r.response.headers.set('X-Inertia-Location', r.uri.toString());
      } else {
        expect(r.headers.value('X-Inertia-Version'), 'updated');
        r.response.write(
          jsonEncode(
            webPage(
              extra: {
                'selectedRecord': selected,
                'currentObject': {
                  'fields': [
                    {'key': 'unpaid_amount', 'readonly': true},
                  ],
                },
                'can': {'update': false},
              },
            ),
          ),
        );
      }
      await r.response.close();
    });
    final data = await api.recordPage('project', 'p1');
    expect(attempts, 2);
    expect(data['selectedRecord'], selected);
    expect(data['can']['update'], false);
    expect(data['currentObject']['fields'].first['readonly'], true);
  });

  test(
    'editor requests include all relation options for create and edit',
    () async {
      final calls = <Uri>[];
      server.listen((r) async {
        calls.add(r.uri);
        expect(
          r.headers.value('X-Inertia-Partial-Data'),
          contains('relationOptions'),
        );
        r.response.write(
          jsonEncode(
            webPage(
              extra: {
                'currentObject': {'fields': []},
                'selectedRecord': r.uri.queryParameters.containsKey('record')
                    ? {'id': 'p1'}
                    : null,
                'can': {'manage_customers': true},
                'relationOptions': {
                  'customer_id': {
                    'items': [
                      {'id': 'c1', 'label': '客户'},
                    ],
                  },
                },
              },
            ),
          ),
        );
        await r.response.close();
      });
      final data = await api.recordPage('project', null, editing: true);
      expect(calls.single.queryParameters, {'per_page': '1'});
      expect(
        data['relationOptions']['customer_id']['items'].single['id'],
        'c1',
      );
      await api.recordPage('project', 'p1', editing: true);
      expect(calls.last.queryParameters['record'], 'p1');
    },
  );

  test('incomplete partial response is rejected instead of showing an empty editor', () async {
    server.listen((r) async {
      r.response.write(
        jsonEncode(
          webPage(
            extra: {
              'selectedRecord': {'id': 'p1'},
              'can': {},
            },
          ),
        ),
      );
      await r.response.close();
    });
    await expectLater(
      api.recordPage('project', 'p1', editing: true),
      throwsA(
        isA<ApiFailure>().having((e) => e.message, 'message', contains('不完整')),
      ),
    );
  });

  test('customer partial requests still reject missing records and expired sessions', () async {
    var expired = false;
    api.user = {'id': 1};
    server.listen((r) async {
      expect(r.uri.path, '/objects/customer');
      r.response.write(
        jsonEncode(
          webPage(
            signedIn: !expired,
            extra: {
              'selectedRecord': null,
              'currentObject': {'fields': []},
              'can': {},
            },
          ),
        ),
      );
      await r.response.close();
    });
    await expectLater(
      api.recordPage('customer', 'missing'),
      throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 404)),
    );
    expired = true;
    await expectLater(
      api.recordPage('customer', 'missing'),
      throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 401)),
    );
    expect(api.user, isNull);
  });
  test('successive reads reuse transport but fetch fresh permissions and record data', () async {
    final ports = <int>[];
    server.listen((r) async {
      ports.add(r.connectionInfo!.remotePort);
      r.response.write(
        jsonEncode(
          webPage(
            extra: {
              'currentObject': {'fields': []},
              'selectedRecord': {'id': 'p1', 'revision': ports.length},
              'can': {'update': ports.length == 1},
            },
          ),
        ),
      );
      await r.response.close();
    });
    final first = await api.recordPage('project', 'p1');
    final second = await api.recordPage('project', 'p1');
    expect(ports, hasLength(2));
    expect(ports[0], ports[1]);
    expect(first['can']['update'], true);
    expect(second['can']['update'], false);
    expect(second['selectedRecord']['revision'], 2);
  });
}
