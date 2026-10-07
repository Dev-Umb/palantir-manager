import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/list_filters.dart';
import 'package:palantir_mobile/projects.dart';
import 'package:palantir_mobile/ui.dart';

import 'business_flow_test.dart' show useBusinessPhone;

class FilterApi extends PalantirApi {
  FilterApi() : super('http://127.0.0.1');
  final requests = <Uri>[];
  bool failNext = false, deferred = false;
  final pending = <String, Completer<Map<String, dynamic>>>{};
  Map<String, dynamic> response(Uri uri, {String title = '筛选结果'}) {
    final project = uri.path.endsWith('project');
    return {
      'currentObject': {
        'fields': [
          if (project)
            {
              'key': 'overall_status',
              'label': '总体状态',
              'type': 'select',
              'options': ['投标中', '已中标'],
            }
          else
            {
              'key': 'level',
              'label': '客户等级',
              'type': 'select',
              'options': ['A', 'B', 'C'],
            },
          {
            'key': 'amount',
            'label': '未回款金额',
            'type': 'number',
            'readonly': true,
          },
          {'key': 'signed_date', 'label': '签订日期', 'type': 'date'},
          {'key': 'owner', 'label': '负责业务员', 'type': 'account'},
          {'key': 'files', 'label': '附件', 'type': 'files'},
        ],
      },
      'relationOptions': {
        'owner': {
          'items': [
            {'id': 7, 'label': '业务员甲'},
          ],
        },
      },
      'records': {
        'data': [
          {
            'id': 'row-${uri.queryParameters['page']}',
            'title': title,
            'payload': {'name': title},
          },
        ],
        'current_page': int.parse(uri.queryParameters['page'] ?? '1'),
        'last_page': 2,
        'total': 2,
      },
    };
  }

  @override
  Future<Map<String, dynamic>> page(
    String path, {
    String? component,
    List<String>? only,
  }) async {
    final uri = Uri.parse(path);
    requests.add(uri);
    if (failNext) {
      failNext = false;
      throw ApiFailure('查询失败，请重试');
    }
    if (deferred) {
      final completer = Completer<Map<String, dynamic>>();
      pending[uri.queryParameters['q'] ?? ''] = completer;
      return completer.future;
    }
    return response(uri);
  }
}

Future<void> mountList(WidgetTester tester, FilterApi api, bool project) async {
  useBusinessPhone(tester);
  await tester.pumpWidget(
    MaterialApp(
      theme: appTheme(),
      home: Scaffold(
        body: project ? ProjectsPage(api: api) : CustomersPage(api: api),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> chooseFilter(WidgetTester tester, bool project) async {
  await tester.tap(find.byKey(const Key('open-list-filters')));
  await tester.pumpAndSettle();
  final field = project ? 'overall_status' : 'level';
  await tester.tap(find.byKey(ValueKey('filter-value-0-$field-equals')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(project ? '已中标' : 'A').last);
  await tester.pumpAndSettle();
  await tester.tap(find.text('应用筛选'));
  await tester.pumpAndSettle();
}

void main() {
  test(
    'query uses existing filters contract and preserves keyword and pagination',
    () {
      const filters = ListFilters(
        logic: 'or',
        rules: [
          RecordFilter('amount', 'between', '-10..0'),
          RecordFilter('level', 'equals', 'A&B'),
          RecordFilter('date', 'is_empty', 'ignored'),
        ],
      );
      final query = Uri.parse(filters.path('customer', ' 中国 &客户 ', 2))
          .queryParameters;
      expect(query['q'], '中国 &客户');
      expect(query['page'], '2');
      expect(query['filter_logic'], 'or');
      expect(query['filters[0][value]'], '-10..0');
      expect(query['filters[1][value]'], 'A&B');
      expect(query.containsKey('filters[2][value]'), false);
      expect(const ListFilters().query, isEmpty);
    },
  );

  test('field types preserve visible readonly fields but exclude unsupported values', () {
    final fields = filterFields([
      {'key': 'visible', 'type': 'number', 'readonly': true},
      {'key': 'files', 'type': 'files'},
      {'key': 'multi', 'type': 'multirelation'},
      {'key': 'item', 'type': 'text', 'scope': 'item'},
    ]);
    expect(fields.map((f) => f['key']), ['visible']);
    expect(
      filterOperators({'type': 'date'}),
      containsAll(['between', 'before']),
    );
    expect(filterOperators({'type': 'select'}), isNot(contains('contains')));
  });

  test('zero, negative and empty predicates remain distinct; invalid ranges are rejected', () {
    const number = {'type': 'number'};
    expect(
      validateFilterValue(number, const RecordFilter('a', 'equals', '0')),
      isNull,
    );
    expect(
      validateFilterValue(number, const RecordFilter('a', 'between', '-10..0')),
      isNull,
    );
    expect(
      validateFilterValue(number, const RecordFilter('a', 'is_empty')),
      isNull,
    );
    expect(
      validateFilterValue(number, const RecordFilter('a', 'equals')),
      isNotNull,
    );
    expect(
      validateFilterValue(number, const RecordFilter('a', 'between', '9..1')),
      isNotNull,
    );
    expect(
      validateFilterValue(number, const RecordFilter('a', 'equals', 'NaN')),
      isNotNull,
    );
    expect(
      validateFilterValue({
        'type': 'date',
      }, const RecordFilter('d', 'between', '2026-02-03..2026-01-01')),
      isNotNull,
    );
    expect(
      validateFilterValue({
        'type': 'date',
      }, const RecordFilter('d', 'equals', '2026-02-30')),
      isNotNull,
    );
  });

  for (final project in [true, false]) {
    final name = project ? 'project' : 'customer';
    testWidgets(
      '$name filters combine with search, page two, cancel, clear and refresh',
      (tester) async {
        final api = FilterApi();
        addTearDown(api.dispose);
        await mountList(tester, api, project);
        await chooseFilter(tester, project);
        final field = project ? 'overall_status' : 'level';
        var query = api.requests.last.queryParameters;
        expect(query['filters[0][field]'], field);
        expect(query['filters[0][value]'], project ? '已中标' : 'A');
        expect(query['page'], '1');
        expect(find.text('筛选（1）'), findsOneWidget);
        await tester.tap(find.text('加载更多'));
        await tester.pumpAndSettle();
        query = api.requests.last.queryParameters;
        expect(query['page'], '2');
        expect(query['filters[0][field]'], field);
        await tester.enterText(find.byType(TextField).first, '目标');
        await tester.pump(const Duration(milliseconds: 400));
        await tester.pumpAndSettle();
        expect(api.requests.last.queryParameters['page'], '1');
        expect(api.requests.last.queryParameters['q'], '目标');
        final count = api.requests.length;
        await tester.tap(find.byKey(const Key('open-list-filters')));
        await tester.pumpAndSettle();
        await tester.tap(find.text('重置'));
        await tester.tap(find.text('取消'));
        await tester.pumpAndSettle();
        expect(api.requests.length, count);
        expect(find.text('筛选（1）'), findsOneWidget);
        final refresh = tester.widget<RefreshIndicator>(
          find.byType(RefreshIndicator),
        );
        await refresh.onRefresh();
        await tester.pumpAndSettle();
        expect(api.requests.last.queryParameters['filters[0][field]'], field);
        await tester.tap(find.text('清空'));
        await tester.pumpAndSettle();
        query = api.requests.last.queryParameters;
        expect(query['q'], '目标');
        expect(query.keys.where((k) => k.startsWith('filters[')), isEmpty);
      },
    );

    testWidgets(
      '$name failed filter query keeps input for retry and hides stale results',
      (tester) async {
        final api = FilterApi();
        addTearDown(api.dispose);
        await mountList(tester, api, project);
        api.failNext = true;
        await chooseFilter(tester, project);
        expect(find.text('查询失败，请重试'), findsOneWidget);
        expect(find.text('加载更多'), findsNothing);
        expect(find.text('筛选结果'), findsNothing);
        expect(find.text('筛选（1）'), findsOneWidget);
        final failedQuery = api.requests.last;
        await tester.tap(find.text('重试'));
        await tester.pumpAndSettle();
        expect(api.requests.last.toString(), failedQuery.toString());
        expect(find.text('筛选结果'), findsWidgets);
      },
    );

    testWidgets('$name late responses cannot replace latest search', (
      tester,
    ) async {
      final api = FilterApi();
      addTearDown(api.dispose);
      await mountList(tester, api, project);
      api.deferred = true;
      await tester.enterText(find.byType(TextField).first, 'old');
      await tester.pump(const Duration(milliseconds: 400));
      final oldUri = api.requests.last;
      await tester.enterText(find.byType(TextField).first, 'new');
      await tester.pump(const Duration(milliseconds: 400));
      api.pending['new']!.complete(
        api.response(api.requests.last, title: '新结果'),
      );
      await tester.pumpAndSettle();
      api.pending['old']!.complete(api.response(oldUri, title: '过时结果'));
      await tester.pumpAndSettle();
      expect(find.text('新结果'), findsWidgets);
      expect(find.text('过时结果'), findsNothing);
    });
  }

  testWidgets(
    'sheet validates missing value and selects authorized relation inline',
    (tester) async {
      final api = FilterApi();
      addTearDown(api.dispose);
      await mountList(tester, api, true);
      await tester.tap(find.byKey(const Key('open-list-filters')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('应用筛选'));
      await tester.pumpAndSettle();
      expect(find.text('请填写筛选值'), findsOneWidget);
      await tester.tap(
        find.byKey(const ValueKey('filter-field-0-overall_status')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('负责业务员').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('relation-筛选值')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('业务员甲'));
      await tester.tap(find.text('业务员甲'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('应用筛选'));
      await tester.pumpAndSettle();
      expect(api.requests.last.queryParameters['filters[0][value]'], '7');
      expect(find.text('负责业务员 等于 业务员甲'), findsOneWidget);
    },
  );
}
