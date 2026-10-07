import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/attachments.dart';
import 'package:palantir_mobile/projects.dart';
import 'package:palantir_mobile/ui.dart';

import 'business_flow_test.dart' show TestApi, useBusinessPhone;

class MaintenanceApi extends TestApi {
  Completer<Map<String, dynamic>>? pending;
  bool loadFails = false;
  @override
  Future<Map<String, dynamic>> get(String path) async {
    if (pending != null) return pending!.future;
    if (loadFails) throw ApiFailure('加载失败');
    final page = await super.get(path);
    page['can'] = {'update': true, 'manage_contracts': true};
    (page['selectedRecord'] as Map)['contracts'] = [
      {
        'id': 'c1',
        'code': 'HT-1',
        'payload': {
          'status': '未签署',
          'amount': 10,
          'statement_attachments': ['/one.pdf', '/two.pdf'],
        },
        'attachment_tokens': {
          'statement_attachments': ['a' * 64, 'b' * 64],
        },
      },
    ];
    return page;
  }
}

void main() {
  test('removal serialization retains editable fields, excludes old URLs and skips undo', () {
    final contract = {
      'id': 'c1',
      'status': '未签署',
      'amount': 10,
      'statement_attachments': ['/old'],
      'removed_attachments': {
        'statement_attachments': ['a' * 64],
      },
      'new_statement_attachments': [UploadFile('new.pdf', Uint8List(1))],
    };
    final submitted = contractWritePayload(contract);
    expect(submitted['removed_attachments'], {
      'statement_attachments': ['a' * 64],
    });
    expect(submitted['statement_attachments'], hasLength(1));
    expect(submitted['id'], 'c1');
    contract['removed_attachments'] = {'statement_attachments': <String>[]};
    expect(
      contractWritePayload(contract),
      isNot(contains('removed_attachments')),
    );
  });

  testWidgets(
    'editor retries failed loading and preserves all three sections',
    (tester) async {
      useBusinessPhone(tester);
      final api = MaintenanceApi()..loadFails = true;
      addTearDown(api.dispose);
      await tester.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: ProjectEditor(api: api, id: 'p1'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('重新加载'), findsOneWidget);
      api.loadFails = false;
      await tester.tap(find.text('重新加载'));
      await tester.pumpAndSettle();
      for (final title in ['项目资料', '客户联系人', '合同']) {
        expect(find.text(title), findsOneWidget);
      }
      expect(find.text('保存修改'), findsOneWidget);
    },
  );

  testWidgets(
    'saved attachment removal can be undone and failed saving keeps its draft',
    (tester) async {
      useBusinessPhone(tester);
      final api = MaintenanceApi()..fail = true;
      addTearDown(api.dispose);
      await tester.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: ProjectEditor(api: api, id: 'p1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('合同'));
      await tester.pumpAndSettle();
      final target = find.byTooltip('移除附件').first;
      await tester.ensureVisible(target);
      await tester.pumpAndSettle();
      await tester.longPress(
        find.ancestor(of: target, matching: find.byType(ListTile)).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('确认移除'));
      await tester.pumpAndSettle();
      expect(find.text('待移除 · 保存后生效'), findsOneWidget);
      await tester.tap(find.byTooltip('撤销移除'));
      await tester.pumpAndSettle();
      expect(find.text('待移除 · 保存后生效'), findsNothing);
      await tester.tap(find.byTooltip('移除附件').first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('确认移除'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('保存修改'));
      await tester.pumpAndSettle();
      expect(
        (api.sent.last['contracts'] as List).first['removed_attachments'],
        {
          'statement_attachments': ['a' * 64],
        },
      );
      expect(find.text('待移除 · 保存后生效'), findsOneWidget);
      expect(find.text('有未保存的修改'), findsOneWidget);
    },
  );

  testWidgets(
    'pending uploads are individually removable even with equal names',
    (tester) async {
      useBusinessPhone(tester);
      final api = MaintenanceApi()..fail = true;
      addTearDown(api.dispose);
      var count = 0;
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(android, (call) async {
            if (call.method == 'pickFile') {
              return {
                'name': 'same.pdf',
                'bytes': Uint8List.fromList([++count]),
              };
            }
            return null;
          });
      addTearDown(
        () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(android, null),
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: ProjectEditor(api: api, id: 'p1'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('合同'));
      await tester.pumpAndSettle();
      for (var i = 0; i < 2; i++) {
        final add = find.widgetWithText(TextButton, '追加附件').last;
        await tester.ensureVisible(add);
        await tester.pumpAndSettle();
        await tester.tap(add);
        await tester.pumpAndSettle();
      }
      final remove = find.byTooltip('移除待上传附件').first;
      await tester.ensureVisible(remove);
      await tester.pumpAndSettle();
      await tester.tap(remove);
      await tester.pumpAndSettle();
      expect(find.text('same.pdf'), findsOneWidget);
      await tester.longPress(
        find
            .ancestor(
              of: find.byTooltip('移除待上传附件'),
              matching: find.byType(ListTile),
            )
            .first,
      );
      await tester.pumpAndSettle();
      expect(find.text('same.pdf'), findsNothing);
      expect(find.text('有未保存的修改'), findsOneWidget);
    },
  );

  testWidgets('loading skeleton respects reduced animation settings', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: const Scaffold(body: EditorLoading()),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('正在加载项目资料…'), findsOneWidget);
  });
}
