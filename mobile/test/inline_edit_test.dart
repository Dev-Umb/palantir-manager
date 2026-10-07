import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/customer_fields.dart';
import 'package:palantir_mobile/projects.dart';
import 'package:palantir_mobile/ui.dart';

import 'business_flow_test.dart' show TestApi, useBusinessPhone;

class ContactApi extends TestApi {
  bool failWrite = false, conflict = false, readOnlyCustomer = false;
  final requests = <Map<String, dynamic>>[];
  @override
  Future<Map<String, dynamic>> page(
    String path, {
    String? component,
    List<String>? only,
  }) => get(path);
  @override
  Future<Map<String, dynamic>> get(String path) async {
    if (path.startsWith('/relation-options')) return {'items': <dynamic>[]};
    final result = await super.get(path);
    if (path.startsWith('/objects/project')) {
      result['can'] = {'update': true, 'manage_customers': true};
      (result['currentObject'] as Map)['fields'] = [
        {'key': 'name', 'label': '项目名称', 'type': 'text'},
        {'key': 'informed_user_ids', 'label': '知会人员', 'type': 'multiaccount'},
      ];
      result['relationOptions'] = {
        'informed_user_ids': {
          'items': [
            {'id': 'u1', 'label': '张经理'},
            {'id': 'u2', 'label': '李经理'},
          ],
        },
      };
      final r = result['selectedRecord'] as Map<String, dynamic>;
      r['customer'] = {
        'id': 'c1',
        'name': '样例客户',
        'address': '武汉',
        'level': 'A',
        'customer_nature': '私企',
        'can_update': !readOnlyCustomer,
      };
      r['contacts'] = [
        {'id': 'contact-1', 'name': '原联系人', 'phone': '13800000000'},
      ];
      (r['payload'] as Map)['customer_id'] = 'c1';
      (r['payload'] as Map)['customer_contact_ids'] = ['contact-1'];
      (r['payload'] as Map)['informed_user_ids'] = ['u1'];
    }
    return result;
  }

  @override
  Future<Map<String, dynamic>> send(
    String path,
    Map<String, dynamic> body, {
    String method = 'POST',
  }) async {
    requests.add({'path': path, 'body': jsonDecode(jsonEncode(body))});
    if (path.endsWith('/preview')) {
      return {
        'conflicts': conflict
            ? [
                {'label': '客户地址', 'current': '武汉', 'submitted': '新地址'},
              ]
            : [],
      };
    }
    if (failWrite) throw ApiFailure('保存失败，输入已保留');
    return {
      'record': {'id': 'p1'},
    };
  }
}

class RouteCounter extends NavigatorObserver {
  final pages = <Route<dynamic>>[];
  int maximum = 0;
  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    pages.add(route);
    if (pages.length > maximum) maximum = pages.length;
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      pages.remove(route);
  @override
  void didRemove(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      pages.remove(route);
}

Future<void> editor(
  WidgetTester tester,
  ContactApi api, {
  RouteCounter? observer,
}) async {
  useBusinessPhone(tester);
  addTearDown(api.dispose);
  await tester.pumpWidget(
    MaterialApp(
      theme: appTheme(),
      navigatorObservers: observer == null ? [] : [observer],
      home: ProjectEditor(api: api, id: 'p1'),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'informed people select inline with matching input decoration and no route',
    (tester) async {
      final api = ContactApi(), observer = RouteCounter();
      await editor(tester, api, observer: observer);
      await tester.tap(find.byKey(const ValueKey('relation-知会人员')));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(CheckboxListTile, '李经理'));
      await tester.pumpAndSettle();
      expect(observer.maximum, 1);
      expect(find.text('张经理、李经理'), findsOneWidget);
      expect(find.byType(InputDecorator), findsWidgets);
      api.failWrite = true;
      await tester.tap(find.text('保存修改'));
      await tester.pumpAndSettle();
      expect(mapOf(api.requests.last['body'])['payload']['informed_user_ids'], [
        'u1',
        'u2',
      ]);
    },
  );

  testWidgets(
    'edit and add contacts in the project, retain rows and money after failed save',
    (tester) async {
      final api = ContactApi()..failWrite = true;
      await editor(tester, api);
      await tester.tap(find.text('客户联系人').first);
      await tester.pumpAndSettle();
      final existing = find.widgetWithText(TextFormField, '联系人姓名 *').first;
      await tester.ensureVisible(existing);
      await tester.pumpAndSettle();
      await tester.enterText(existing, '修改后的联系人');
      await tester.ensureVisible(find.text('添加联系人'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('添加联系人'));
      await tester.pumpAndSettle();
      final added = find.widgetWithText(TextFormField, '联系人姓名 *').last;
      await tester.ensureVisible(added);
      await tester.pumpAndSettle();
      await tester.enterText(added, '新增联系人');
      final phone = find.widgetWithText(TextFormField, '手机号').last;
      await tester.ensureVisible(phone);
      await tester.pumpAndSettle();
      await tester.enterText(phone, '13900000000');
      await tester.tap(find.text('保存修改'));
      await tester.pumpAndSettle();
      expect(api.requests.first['path'], '/project-customer-profile/preview');
      final payload = mapOf(mapOf(api.requests.last['body'])['payload']);
      expect(payload['unpaid_amount'], -50000);
      expect(payload['customer_profile']['contacts'], [
        {'id': 'contact-1', 'name': '修改后的联系人', 'phone': '13800000000'},
        {'id': null, 'name': '新增联系人', 'phone': '13900000000'},
      ]);
      expect(find.text('新增联系人'), findsOneWidget);
      expect(find.text('保存失败，输入已保留'), findsOneWidget);
    },
  );

  testWidgets(
    'customer conflicts stay inline and do not save without confirmation',
    (tester) async {
      final api = ContactApi()..conflict = true;
      await editor(tester, api);
      await tester.tap(find.text('客户联系人').first);
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextFormField, '客户地址'), '新地址');
      await tester.tap(find.text('保存修改'));
      await tester.pumpAndSettle();
      expect(api.requests.length, 1);
      expect(find.text('客户资料存在冲突'), findsOneWidget);
      await tester.ensureVisible(find.text('取消，继续编辑'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('取消，继续编辑'));
      await tester.pumpAndSettle();
      expect(api.requests.length, 1);
      expect(find.text('新地址'), findsOneWidget);
    },
  );

  testWidgets(
    'read-only customer keeps contact association but disallows edits and new contacts',
    (tester) async {
      final api = ContactApi()..readOnlyCustomer = true;
      await editor(tester, api);
      await tester.tap(find.text('客户联系人').first);
      await tester.pumpAndSettle();
      expect(find.text('添加联系人'), findsNothing);
      expect(find.byKey(const ValueKey('relation-选择已有联系人')), findsOneWidget);
      final contact = find.widgetWithText(TextFormField, '联系人姓名 *');
      final input = find.descendant(
        of: contact,
        matching: find.byType(TextField),
      );
      expect(tester.widget<TextField>(input).readOnly, true);
    },
  );

  test('contact payload excludes local row keys and does not discard invalid blank rows', () {
    final body = customerProfilePayload({
      'customer_id': 'c1',
      'name': ' 客户 ',
      'contacts': [
        {'id': null, '_key': 'local-1', 'name': '', 'phone': '123'},
      ],
    });
    expect(body['name'], '客户');
    expect(body['contacts'], [
      {'id': null, 'name': '', 'phone': '123'},
    ]);
    expect(body['overwrite_confirmed'], false);
  });

  testWidgets(
    'cross-module project links restart a shallow flow and editor back keeps its parent',
    (tester) async {
      useBusinessPhone(tester);
      final observer = RouteCounter(), api = ContactApi();
      addTearDown(api.dispose);
      await tester.pumpWidget(
        MaterialApp(
          navigatorObservers: [observer],
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => push(
                  context,
                  Builder(
                    builder: (context) => Scaffold(
                      body: TextButton(
                        onPressed: () => openFlow(
                          context,
                          ProjectDetail(api: api, id: 'p1'),
                        ),
                        child: const Text('查看关联项目'),
                      ),
                    ),
                  ),
                ),
                child: const Text('通知入口'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('通知入口'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('查看关联项目'));
      await tester.pumpAndSettle();
      expect(observer.pages.length, 2);
      final context = tester.element(find.byType(ProjectDetail));
      push(context, ProjectEditor(api: api, id: 'p1'));
      await tester.pumpAndSettle();
      expect(observer.pages.length, 3);
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.byType(ProjectDetail), findsOneWidget);
    },
  );
}
