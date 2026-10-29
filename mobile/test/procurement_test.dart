import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/secondary.dart';
import 'package:palantir_mobile/ui.dart';
import 'package:palantir_mobile/assistant.dart';

class ProcurementApi extends PalantirApi {
  ProcurementApi() : super('http://localhost') {
    user = {'id': 1};
    session = {
      'permissions': ['ai.harness.view'],
      'can_read_procurement': true,
    };
  }
  final paths = <String>[];
  final notice = {
    'id': 1,
    'title': '宜上高速 / 骨架钢模板',
    'priority': 'P1',
    'method': '询价采购',
    'has_issues': true,
    'payload': {
      'unit': '采购单位',
      'quantity': '100套',
      'registration': '公告未单列',
      'deadline': '2026-10-27 09:00',
      'issues': '核对图纸',
      'material': '完整规格',
      'terms': '完整采购条款',
      'url': 'https://example.com',
      'evidence': ['原文.txt'],
    },
  };
  @override
  Future<Map<String, dynamic>> page(
    String path, {
    String? component,
    List<String>? only,
  }) async {
    paths.add(path);
    if (path.contains('/notices/')) {
      return {
        'notice': notice,
        'warnings': ['文件未上传'],
      };
    }
    return {
      'notices': {
        'data': [notice],
        'total': 42,
        'current_page': 1,
        'last_page': 3,
      },
      'totals': {
        'count': 42,
        'priority': {'P1': 12},
      },
      'methods': ['询价采购'],
    };
  }

  @override
  Future<Map<String, dynamic>> get(String path) async => {};
}

void main() {
  testWidgets('cards use server totals and navigate to complete raw details', (
    t,
  ) async {
    final api = ProcurementApi();
    await t.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: ProcurementPage(api: api),
      ),
    );
    await t.pumpAndSettle();
    expect(find.text('42'), findsOneWidget);
    expect(find.text('100套'), findsOneWidget);
    expect(find.textContaining('核对图纸'), findsOneWidget);
    await t.tap(find.text('查看完整详情 →'));
    await t.pumpAndSettle();
    expect(find.text('公告详情'), findsOneWidget);
    expect(api.paths.last, '/procurement-hub/notices/1');
    await t.scrollUntilVisible(
      find.text('完整采购条款'),
      350,
      scrollable: find
          .descendant(
            of: find.byType(ListView),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    expect(find.text('完整采购条款'), findsOneWidget);
    await t.pageBack();
    await t.pumpAndSettle();
    expect(find.text('42'), findsOneWidget);
  });
  testWidgets(
    'filter cancellation preserves query; priority is sent to server',
    (t) async {
      final api = ProcurementApi();
      await t.pumpWidget(
        MaterialApp(
          theme: appTheme(),
          home: ProcurementPage(api: api),
        ),
      );
      await t.pumpAndSettle();
      await t.tap(find.byTooltip('筛选'));
      await t.pumpAndSettle();
      expect(find.text('筛选招采信息'), findsOneWidget);
      await t.tap(find.text('取消'));
      await t.pumpAndSettle();
      expect(api.paths.length, 1);
      await t.tap(find.widgetWithText(ChoiceChip, '优先对接 P1'));
      await t.pumpAndSettle();
      expect(Uri.parse(api.paths.last).queryParameters['priority'], 'P1');
    },
  );
  testWidgets('AI context appends draft and does not create a run', (t) async {
    final api = ProcurementApi();
    api.assistantState['draft'] = '原问题';
    await t.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        home: AssistantPage(api: api, initialPrompt: '招采记录 ID 1'),
      ),
    );
    await t.pumpAndSettle();
    expect(find.text('原问题\n\n招采记录 ID 1'), findsOneWidget);
    expect(api.paths, ['/ai']);
  });
  testWidgets('375px overview and filters remain usable with large text', (
    t,
  ) async {
    t.view.physicalSize = const Size(375, 812);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    addTearDown(t.view.resetDevicePixelRatio);
    final api = ProcurementApi();
    await t.pumpWidget(
      MaterialApp(
        theme: appTheme(),
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context)
              .copyWith(textScaler: const TextScaler.linear(1.3)),
          child: child!,
        ),
        home: ProcurementPage(api: api),
      ),
    );
    await t.pumpAndSettle();
    expect(t.takeException(), isNull);
    await t.tap(find.byTooltip('筛选'));
    await t.pumpAndSettle();
    expect(t.takeException(), isNull);
    await t.tap(find.text('重置'));
    await t.tap(find.text('应用筛选'));
    await t.pumpAndSettle();
    expect(t.takeException(), isNull);
    expect(Uri.parse(api.paths.last).queryParameters['priority'], '');
  });
}
