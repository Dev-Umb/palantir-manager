import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/updates.dart';
import 'package:palantir_mobile/ui.dart';

class RealHttpOverrides extends HttpOverrides {}

class LocalUpdateClient implements HttpClient {
  final HttpClient delegate = RealHttpOverrides().createHttpClient(null);
  final Uri local;
  LocalUpdateClient(this.local);
  @override
  set connectionTimeout(Duration? value) => delegate.connectionTimeout = value;
  @override
  Future<HttpClientRequest> getUrl(Uri url) =>
      delegate.getUrl(local.resolve(url.path));
  @override
  void close({bool force = false}) => delegate.close(force: force);
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

Map<String, dynamic> manifest({int code = 8}) => {
  'versionCode': code,
  'versionName': '1.0.6',
  'notes': '更新与附件修复',
  'sha256': 'a' * 64,
  'sizeBytes': 1024,
  'packageName': appPackage,
  'apkUrl': 'https://palantir.umb.ink/app-updates/app.apk',
};

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final origin = Uri.parse('https://palantir.umb.ink');

  test(
    'rejects foreign origins, oversized packages and invalid manifest fields',
    () {
      expect(AppRelease.parse(manifest(), origin).code, 8);
      for (final invalid in [
        {...manifest(), 'apkUrl': 'https://elsewhere.test/app.apk'},
        {...manifest(), 'apkUrl': 'http://palantir.umb.ink/app.apk'},
        {...manifest(), 'apkUrl': 'https://user@palantir.umb.ink/app.apk'},
        {...manifest(), 'sha256': 'bad'},
        {...manifest(), 'sizeBytes': 151 * 1024 * 1024},
        {...manifest(), 'versionCode': '8'},
        {...manifest(), 'packageName': 'other.app'},
      ]) {
        expect(() => AppRelease.parse(invalid, origin), throwsFormatException);
      }
    },
  );

  test(
    'only newer versions prompt and requests carry no business credentials',
    () async {
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      addTearDown(() => server.close(force: true));
      final local = Uri.parse('http://127.0.0.1:${server.port}');
      var code = 8;
      server.listen((request) async {
        expect(request.uri.path, '/app-updates/android.json');
        expect(request.cookies, isEmpty);
        expect(request.headers.value('Authorization'), isNull);
        request.response.write(jsonEncode(manifest(code: code)));
        await request.response.close();
      });
      final service = UpdateService(
        createClient: () => LocalUpdateClient(local),
      );
      expect((await service.check(7))?.code, 8);
      expect(await service.check(8), isNull);
      code = 6;
      expect(await service.check(8), isNull);
    },
  );

  test('failed or redirected checks do not follow another endpoint', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    addTearDown(() => server.close(force: true));
    var requests = 0;
    server.listen((request) async {
      requests++;
      request.response.statusCode = 302;
      request.response.headers.set('Location', 'https://other.test/update');
      await request.response.close();
    });
    final service = UpdateService(
      createClient: () =>
          LocalUpdateClient(Uri.parse('http://127.0.0.1:${server.port}')),
    );
    await expectLater(service.check(7), throwsA(isA<HttpException>()));
    expect(requests, 1);
  });

  tearDown(
    () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updateChannel, null),
  );

  testWidgets('postponing an update never downloads or installs', (
    tester,
  ) async {
    final calls = <String>[];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updateChannel, (call) async {
          calls.add(call.method);
          return null;
        });
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () => showDialog<void>(
              context: context,
              builder: (_) =>
                  UpdateDialog(release: AppRelease.parse(manifest(), origin)),
            ),
            child: const Text('open'),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('稍后'));
    await tester.pumpAndSettle();
    expect(calls, isEmpty);
  });

  testWidgets('download validation errors stay actionable and never install', (
    tester,
  ) async {
    final calls = <String>[];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updateChannel, (call) async {
          calls.add(call.method);
          if (call.method == 'downloadUpdate') {
            throw PlatformException(code: 'update', message: '安装包签名不符');
          }
          return {'bytes': 0};
        });
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: UpdateDialog(release: AppRelease.parse(manifest(), origin)),
      ),
    );
    await tester.tap(find.text('下载更新'));
    await tester.pumpAndSettle();
    expect(find.text('安装包签名不符'), findsOneWidget);
    expect(find.text('下载更新'), findsOneWidget);
    expect(calls, isNot(contains('installUpdate')));
  });

  testWidgets('cancelling a download restores retry without installing', (
    tester,
  ) async {
    final download = Completer<String>();
    final calls = <String>[];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updateChannel, (call) async {
          calls.add(call.method);
          if (call.method == 'downloadUpdate') {
            return download.future;
          }
          if (call.method == 'cancelUpdate') {
            download.completeError(
              PlatformException(code: 'update', message: '下载已取消'),
            );
            return null;
          }
          return {'bytes': 0};
        });
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: UpdateDialog(release: AppRelease.parse(manifest(), origin)),
      ),
    );
    await tester.tap(find.text('下载更新'));
    await tester.pump();
    expect(find.text('取消下载'), findsOneWidget);
    await tester.tap(find.text('取消下载'));
    await tester.pumpAndSettle();
    expect(find.text('下载已取消'), findsOneWidget);
    expect(find.text('下载更新'), findsOneWidget);
    expect(calls, isNot(contains('installUpdate')));
  });

  testWidgets(
    'permission refusal offers continuation without reporting installation success',
    (tester) async {
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(updateChannel, (call) async {
            if (call.method == 'downloadUpdate') {
              return '/cache/updates/update.apk';
            }
            if (call.method == 'installUpdate') return 'permission_required';
            return {'bytes': 1024};
          });
      await tester.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: UpdateDialog(release: AppRelease.parse(manifest(), origin)),
        ),
      );
      await tester.tap(find.text('下载更新'));
      await tester.pumpAndSettle();
      expect(find.textContaining('请允许安装'), findsOneWidget);
      expect(find.text('继续安装'), findsOneWidget);
      expect(find.text('安装成功'), findsNothing);
    },
  );
}
