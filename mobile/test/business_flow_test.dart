import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/main.dart';
import 'package:palantir_mobile/assistant.dart';
import 'package:palantir_mobile/projects.dart';
import 'package:palantir_mobile/ui.dart';

class TestApi extends PalantirApi {
  TestApi() : super('http://127.0.0.1:8765') {
    user = {'name': '张经理', 'email': 'test@example.test'};
    session = {
      'permissions': ['ai.harness.view', 'object.project.view'],
    };
  }
  bool fail = false;
  final requestedPages = <String>[];
  final sent = <Map<String, dynamic>>[];
  @override
  Future<Map<String, dynamic>> page(
    String path, {
    String? component,
    List<String>? only,
  }) {
    requestedPages.add(path);
    return get(path);
  }

  @override
  Future<Map<String, dynamic>> get(String path) async {
    if (path.startsWith('/objects/project')) {
      return {
        'records': {'data': []},
        'currentObject': {
          'fields': [
            {'key': 'name', 'label': '项目名称', 'type': 'text'},
            {'key': 'remark', 'label': '跟进备注', 'type': 'text'},
          ],
        },
        'selectedRecord': {
          'id': 'p1',
          'code': 'XM-1',
          'title': '样例项目',
          'can_update': false,
          'payload': {
            'name': '样例项目',
            'unpaid_amount': -50000,
            'paid_amount': 550000,
            'occurred_amount': 500000,
            'remark': '原备注',
          },
        },
        'can': {'update': false},
      };
    }
    return {};
  }

  @override
  Future<Map<String, dynamic>> send(
    String path,
    Map<String, dynamic> body, {
    String method = 'POST',
  }) async {
    sent.add({...body});
    if (fail) throw ApiFailure('暂时无法连接，输入已保留');
    return {'answer': '已核对项目主档', 'conversation_id': 'conversation-1'};
  }
}

void useBusinessPhone(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2340);
  tester.view.devicePixelRatio = 420 / 160;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

void main() {
  test('canonical amount keeps missing, zero and negatives distinct', () {
    expect(amount(null), '未填写');
    expect(amount(0), '0.00');
    expect(amount(-50000, wan: true), '-5.00 万');
    expect(
      progress({'occurred_amount': 500000, 'paid_amount': 550000}),
      '110.00%',
    );
    expect(progress({'occurred_amount': 0, 'paid_amount': 100}), '—');
  });
  testWidgets('four business tabs with AI on home and no admin tab', (
    tester,
  ) async {
    final api = TestApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: BusinessShell(api: api),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(NavigationDestination), findsNWidgets(4));
    expect(find.text('AI 助手'), findsOneWidget);
    expect(find.text('管理员'), findsNothing);
    await tester.tap(find.text('AI 助手'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ai-input')), findsOneWidget);
    expect(find.byTooltip('发送'), findsOneWidget);
    expect(find.byType(NavigationBar), findsNothing);
  });
  testWidgets(
    'failed AI send preserves draft and successful follow-up preserves conversation',
    (tester) async {
      useBusinessPhone(tester);
      final api = TestApi();
      addTearDown(api.dispose);
      await tester.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: AssistantPage(api: api),
        ),
      );
      await tester.pumpAndSettle();
      final input = find.byKey(const Key('ai-input'));
      await tester.enterText(input, '核对未回款');
      api.fail = true;
      await tester.pump();
      await tester.tap(find.byTooltip('发送'));
      await tester.pumpAndSettle();
      expect(tester.widget<TextField>(input).controller!.text, '核对未回款');
      expect(find.text('暂时无法连接，输入已保留'), findsWidgets);
      api.fail = false;
      await tester.pump();
      await tester.tap(find.byTooltip('发送'));
      await tester.pumpAndSettle();
      expect(tester.widget<TextField>(input).controller!.text, isEmpty);
      await tester.enterText(input, '继续看合同');
      await tester.pump();
      await tester.tap(find.byTooltip('发送'));
      await tester.pumpAndSettle();
      expect(api.sent.last['conversation_id'], 'conversation-1');
    },
  );
  testWidgets('read-only project retains finance tab and negative amount', (
    tester,
  ) async {
    final api = TestApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: ProjectDetail(api: api, id: 'p1'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('编辑项目'), findsNothing);
    expect(find.text('此项目仅可查看，当前没有编辑权限。'), findsOneWidget);
    await tester.tap(find.text('回款'));
    await tester.pumpAndSettle();
    expect(find.text('-50000.00'), findsOneWidget);
    expect(find.text('110.00%'), findsOneWidget);
  });
  testWidgets('failed project save keeps draft', (tester) async {
    useBusinessPhone(tester);
    final api = TestApi()..fail = true;
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: ProjectEditor(api: api, id: 'p1'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '修改后项目');
    await tester.tap(find.text('保存修改'));
    await tester.pumpAndSettle();
    expect(find.text('修改后项目'), findsOneWidget);
    expect(api.sent.single['payload']['name'], '修改后项目');
    expect(find.text('暂时无法连接，输入已保留'), findsWidgets);
  });
  testWidgets('AI draft survives returning home and empty input cannot send', (
    tester,
  ) async {
    final api = TestApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: BusinessShell(api: api),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('AI 助手'));
    await tester.pumpAndSettle();
    expect(
      tester.widget<IconButton>(find.byKey(const Key('ai-send'))).onPressed,
      isNull,
    );
    await tester.enterText(find.byKey(const Key('ai-input')), '待发送的问题');
    await tester.pageBack();
    await tester.pumpAndSettle();
    await tester.tap(find.text('AI 助手'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('ai-input')))
          .controller!
          .text,
      '待发送的问题',
    );
  });
  testWidgets('editing then immediately returning asks to keep draft', (
    tester,
  ) async {
    final api = TestApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => push(context, ProjectEditor(api: api, id: 'p1')),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '未保存项目');
    await tester.pump();
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('还有未保存的修改'), findsOneWidget);
    await tester.tap(find.text('继续编辑'));
    await tester.pumpAndSettle();
    expect(find.text('未保存项目'), findsOneWidget);
  });
  test('contract submission retains editable fields and never resends projected fields or old attachment URLs', () {
    final file = UploadFile('test.pdf', Uint8List.fromList([1, 2, 3]));
    final result = contractWritePayload({
      'id': 'c1',
      'status': '未签署',
      'amount': 20,
      'remark': '原备注',
      'project_id': 'p1',
      'code': 'HT-1',
      'contract_attachments': ['/attachments/c1/contract_attachments/0'],
      'new_processing_letter_attachments': [file],
    });
    expect(result, {
      'id': 'c1',
      'status': '未签署',
      'amount': 20,
      'remark': '原备注',
      'processing_letter_attachments': [file],
    });
  });
  testWidgets(
    'tabs load on first visit and preserve search on subsequent switches',
    (tester) async {
      useBusinessPhone(tester);
      final api = TestApi();
      addTearDown(api.dispose);
      await tester.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: BusinessShell(api: api),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        api.requestedPages.where((p) => p.startsWith('/objects/')),
        isEmpty,
      );
      await tester.tap(find.byType(NavigationDestination).at(1));
      await tester.pumpAndSettle();
      expect(
        api.requestedPages.where((p) => p.startsWith('/objects/project')),
        hasLength(1),
      );
      expect(
        api.requestedPages.where((p) => p.startsWith('/objects/customer')),
        isEmpty,
      );
      await tester.enterText(find.byType(TextField).first, '保留查询');
      await tester.pump(const Duration(milliseconds: 400));
      await tester.pumpAndSettle();
      final count = api.requestedPages.length;
      await tester.tap(find.byType(NavigationDestination).at(2));
      await tester.pumpAndSettle();
      expect(
        api.requestedPages.where((p) => p.startsWith('/objects/customer')),
        hasLength(1),
      );
      await tester.tap(find.byType(NavigationDestination).at(1));
      await tester.pumpAndSettle();
      expect(api.requestedPages.length, count + 1);
      expect(
        tester.widget<TextField>(find.byType(TextField).first).controller!.text,
        '保留查询',
      );
    },
  );
}
