import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/main.dart';

class RestoreApi extends PalantirApi {
  RestoreApi() : super('http://127.0.0.1:8765');
  Completer<bool>? pending;
  bool fail = false, cleared = false;
  bool? remembered;
  int restores = 0;
  @override
  Future<bool> restoreSession() async {
    restores++;
    if (fail) throw ApiFailure('offline');
    final success = await (pending?.future ?? Future.value(false));
    if (success) {
      user = {'id': 1, 'name': '恢复测试'};
      notifyListeners();
    }
    return success;
  }

  @override
  Future<void> clearSession() async {
    cleared = true;
    await super.clearSession();
  }

  @override
  Future<void> login(
    String email,
    String password, {
    bool remember = true,
  }) async {
    remembered = remember;
    throw ApiFailure('账号或密码不正确。');
  }

  @override
  Future<Map<String, dynamic>> get(String path) async => {};
}

void main() {
  testWidgets(
    'restoration hides login and business until validated then opens home',
    (tester) async {
      final api = RestoreApi()..pending = Completer<bool>();
      addTearDown(api.dispose);
      await tester.pumpWidget(PalantirApp(api: api));
      expect(find.text('正在恢复登录…'), findsOneWidget);
      expect(find.byType(LoginPage), findsNothing);
      expect(find.byType(BusinessShell), findsNothing);
      api.pending!.complete(true);
      await tester.pumpAndSettle();
      expect(find.byType(BusinessShell), findsOneWidget);
      expect(find.byType(LoginPage), findsNothing);
    },
  );

  testWidgets(
    'temporary restore failure offers retry without clearing saved login',
    (tester) async {
      final api = RestoreApi()..fail = true;
      addTearDown(api.dispose);
      await tester.pumpWidget(PalantirApp(api: api));
      await tester.pumpAndSettle();
      expect(find.text('重试'), findsOneWidget);
      expect(find.text('重新登录'), findsOneWidget);
      expect(find.byType(LoginPage), findsNothing);
      expect(api.cleared, false);
      api.fail = false;
      await tester.tap(find.text('重试'));
      await tester.pumpAndSettle();
      expect(api.restores, 2);
      expect(find.byType(LoginPage), findsOneWidget);
      expect(api.cleared, false);
    },
  );

  testWidgets(
    'failed local clear shows a retry gate instead of a login success',
    (tester) async {
      final api = RestoreApi()..sessionClearFailed = true;
      addTearDown(api.dispose);
      await tester.pumpWidget(PalantirApp(api: api));
      await tester.pumpAndSettle();
      expect(find.text('无法清除设备上的登录信息，请重试。'), findsOneWidget);
      expect(find.byType(LoginPage), findsNothing);
      await tester.tap(find.text('重试'));
      await tester.pumpAndSettle();
      expect(api.cleared, true);
      expect(find.byType(LoginPage), findsOneWidget);
    },
  );

  testWidgets(
    'explicit re-login clears saved state and preserves password visibility and input on failure',
    (tester) async {
      final api = RestoreApi()..fail = true;
      addTearDown(api.dispose);
      await tester.pumpWidget(PalantirApp(api: api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('重新登录'));
      await tester.pumpAndSettle();
      expect(api.cleared, true);
      expect(
        tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,
        true,
      );
      await tester.enterText(
        find.byType(TextField).at(0),
        'fixture@example.test',
      );
      await tester.enterText(find.byType(TextField).at(1), 'fixture');
      await tester.ensureVisible(find.byIcon(Icons.visibility_outlined));
      await tester.tap(find.byIcon(Icons.visibility_outlined));
      await tester.pump();
      expect(
        tester.widget<TextField>(find.byType(TextField).at(1)).obscureText,
        false,
      );
      await tester.ensureVisible(find.text('保持登录'));
      await tester.tap(find.text('保持登录'));
      await tester.pump();
      await tester.ensureVisible(find.text('登录'));
      await tester.tap(find.text('登录'));
      await tester.pumpAndSettle();
      expect(api.remembered, false);
      expect(find.text('账号或密码不正确。'), findsOneWidget);
      expect(find.text('fixture@example.test'), findsOneWidget);
      expect(find.text('fixture'), findsOneWidget);
    },
  );
}
