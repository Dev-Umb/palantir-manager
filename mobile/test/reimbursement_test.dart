import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/reimbursements.dart';

void main() {
  test('dates are readable and invalid dates have an explicit placeholder', () {
    expect(reimbursementDate('2026-10-28T09:32:00'), '2026-10-28 09:32');
    expect(reimbursementDate('invalid'), '—');
  });
  testWidgets(
    'record card preserves title status amount and navigation at narrow width',
    (tester) async {
      tester.view.physicalSize = const Size(320, 720);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      Map<String, dynamic>? selected;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ReimbursementList(
              api: ListReviewApi(),
              path: '/reimbursements',
              open: (r) => selected = r,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('BX-1 · V2'), findsOneWidget);
      expect(find.text('审批中'), findsOneWidget);
      expect(find.text('¥712.00'), findsOneWidget);
      expect(find.text('差旅交通 · 2026-10-28 09:32'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.tap(find.text('苏州客户拜访差旅报销及现场技术沟通交通费用'));
      expect(selected?['id'], 1);
    },
  );
  test('money uses integer cents and rejects invalid precision and zero', () {
    expect(reimbursementCents('0.10')! + reimbursementCents('0.20')!, 30);
    expect(reimbursementCents('12.3'), 1230);
    for (final bad in [
      '0',
      '-1',
      '0.001',
      '1e2',
      '',
      '100000000',
      '01',
      '00.10',
    ]) {
      expect(reimbursementCents(bad), null);
    }
  });
  test('submission keys are UUIDs and distinct', () {
    final keys = List.generate(100, (_) => reimbursementKey());
    expect(keys.toSet().length, 100);
    for (final key in keys) {
      expect(
        RegExp(
          r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
        ).hasMatch(key),
        isTrue,
      );
    }
  });
  testWidgets(
    'detail preserves information and invoices without mobile approval actions',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ReimbursementDetail(
            api: ReimbursementApi(),
            path: '/reimbursements/versions/1',
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('申请信息'), findsOneWidget);
      expect(find.text('审批记录'), findsNothing);
      expect(find.text('演示审批人'), findsNothing);
      expect(find.text('同意'), findsNothing);
      expect(find.text('费用明细'), findsOneWidget);
      expect(find.text('一键下载全部发票'), findsOneWidget);
    },
  );
  testWidgets('resubmit stays above the system gesture area', (tester) async {
    tester.view.padding = const FakeViewPadding(bottom: 90);
    addTearDown(tester.view.resetPadding);
    await tester.pumpWidget(
      MaterialApp(
        home: ReimbursementDetail(
          api: ReimbursementApi()..canResubmit = true,
          path: '/reimbursements/versions/1',
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('修改并重新提交'), 300);
    await tester.pumpAndSettle();
    final screen =
        tester.view.physicalSize.height / tester.view.devicePixelRatio;
    expect(
      tester.getRect(find.widgetWithText(FilledButton, '修改并重新提交')).bottom,
      lessThanOrEqualTo(screen - 90 / tester.view.devicePixelRatio),
    );
  });
  testWidgets('successful submission returns its detail route to the caller', (
    tester,
  ) async {
    final api = ReimbursementApi()..succeed = true;
    String? destination;
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () async {
              destination = await Navigator.of(context).push<String>(
                MaterialPageRoute(
                  builder: (_) => ReimbursementForm(
                    api: api,
                    previous: {
                      'title': '差旅',
                      'note': '说明',
                      'type': '差旅交通',
                      'reimbursement_id': 1,
                      'number': 1,
                      'cc': [],
                      'items': [
                        {
                          'description': '车票',
                          'amount': '1.00',
                          'attachments': [
                            {'id': 'invoice', 'name': 'invoice.pdf'},
                          ],
                        },
                      ],
                    },
                  ),
                ),
              );
            },
            child: const Text('打开申请'),
          ),
        ),
      ),
    );
    await tester.tap(find.text('打开申请'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('提交申请'));
    await tester.pumpAndSettle();
    expect(destination, '/reimbursements/versions/2');
    expect(find.text('打开申请'), findsOneWidget);
    expect(find.byType(ReimbursementDetail), findsNothing);
  });
  testWidgets(
    'form uploads before submitting integer-cent equivalent amount and preserves failed draft',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(800, 1800));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final api = ReimbursementApi();
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(
            const MethodChannel('palantir/android'),
            (call) async => {
              'name': 'invoice.pdf',
              'bytes': Uint8List.fromList([37, 80, 68, 70]),
            },
          );
      addTearDown(
        () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(
              const MethodChannel('palantir/android'),
              null,
            ),
      );
      await tester.pumpWidget(MaterialApp(home: ReimbursementForm(api: api)));
      await tester.pumpAndSettle();
      final fields = find.byType(TextFormField);
      await tester.enterText(fields.at(0), '客户拜访');
      await tester.enterText(fields.at(1), '差旅说明');
      await tester.enterText(fields.at(2), '车票');
      await tester.enterText(fields.at(3), '0.10');
      await tester.tap(find.text('添加发票（图片 / PDF）'));
      await tester.pumpAndSettle();
      expect(find.text('上传成功'), findsOneWidget);
      expect(find.text('合计 ¥0.10'), findsOneWidget);
      await tester.tap(find.text('提交申请'));
      await tester.pumpAndSettle();
      expect(api.payload?['items'][0]['amount'], '0.10');
      expect(api.payload?['items'][0]['attachments'], ['invoice']);
      expect(find.textContaining('再次提交将重试同一申请'), findsOneWidget);
    },
  );
}

class ReimbursementApi extends PalantirApi {
  ReimbursementApi() : super('http://127.0.0.1');
  Map<String, dynamic>? payload;
  bool succeed = false;
  bool canResubmit = false;
  @override
  Future<Map<String, dynamic>> get(String path) async =>
      path.endsWith('/options')
      ? {
          'users': [],
          'capabilities': {'submit': true},
        }
      : {
          'record': {
            'id': 1,
            'reimbursement_id': 1,
            'number': 1,
            'title': '差旅',
            'type': '差旅交通',
            'note': '出差',
            'total_cents': 10,
            'status': 'pending',
            'node_index': 0,
            'owner': {'name': '业务员'},
            'created_at': '2026-10-28',
            'versions': [],
            'items': [],
            'flow': {'nodes': [], 'cc': []},
            'cc': [],
            'decisions': [],
            'zip_url': '/zip',
            'can_resubmit': canResubmit,
          },
        };
  @override
  Future<Map<String, dynamic>> multipart(
    String path,
    Map<String, dynamic> values, {
    String method = 'POST',
  }) async => {
    'file': {
      'id': 'invoice',
      'name': 'invoice.pdf',
      'mime': 'application/pdf',
      'size': 4,
    },
  };
  @override
  Future<Map<String, dynamic>> send(
    String path,
    Map<String, dynamic> body, {
    String method = 'POST',
  }) async {
    payload = body;
    if (succeed) {
      return {
        'record': {'url': '/reimbursements/versions/2'},
      };
    }
    throw ApiFailure('网络中断');
  }
}

class ListReviewApi extends PalantirApi {
  ListReviewApi() : super('http://127.0.0.1');
  @override
  Future<Map<String, dynamic>> get(String path) async => {
    'records': {
      'data': [
        {
          'id': 1,
          'reimbursement_id': 1,
          'number': 2,
          'title': '苏州客户拜访差旅报销及现场技术沟通交通费用',
          'type': '差旅交通',
          'status': 'pending',
          'total_cents': 71200,
          'created_at': '2026-10-28T09:32:00',
        },
      ],
      'next_page_url': null,
    },
  };
}
