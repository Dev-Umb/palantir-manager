import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:palantir_mobile/api.dart';
import 'package:palantir_mobile/quotation.dart';
import 'package:palantir_mobile/assistant.dart';

class QuoteApi extends PalantirApi {
  QuoteApi() : super('http://127.0.0.1');
  String? path;
  Map<String, dynamic>? payload;
  bool fail = false;
  @override
  Future<Map<String, dynamic>> page(
    String path, {
    String? component,
    List<String>? only,
  }) async => {
    'conversations': [
      {'id': 'c1', 'title': '我的报价'},
    ],
  };
  @override
  Future<Map<String, dynamic>> send(
    String path,
    Map<String, dynamic> body, {
    String method = 'POST',
  }) async {
    this.path = path;
    payload = body;
    if (fail) throw ApiFailure('请核对计价单位');
    if (path == '/ai/runs') {
      return {
        'conversation_id': 'c1',
        'run': {
          'id': 'r1',
          'status': 'completed',
          'answer': '请核对报价',
          'artifacts': [quote()],
        },
      };
    }
    if (path == '/ai/messages') {
      return {'answer': '业务查询结果'};
    }
    return {'artifact': quote(generated: true)};
  }

  @override
  Future<Map<String, dynamic>> get(String path) async => {
    'messages': [
      {'role': 'user', 'content': '做个报价单'},
    ],
    'runs': [
      {
        'id': 'r1',
        'artifacts': [quote(generated: true)],
      },
    ],
  };
  @override
  Future<Uint8List> download(String path) async {
    this.path = path;
    return Uint8List.fromList([80, 75]);
  }
}

class PreviewQuoteApi extends QuoteApi {
  @override
  Future<Uint8List> download(String path) async {
    this.path = path;
    return Uint8List.fromList('%PDF-1.4'.codeUnits);
  }
}

class PendingReplyApi extends QuoteApi {
  final response = Completer<Map<String, dynamic>>();
  @override
  Future<Map<String, dynamic>> send(
    String path,
    Map<String, dynamic> body, {
    String method = 'POST',
  }) => response.future;
}

Map<String, dynamic> quote({bool generated = false}) => {
  'id': 'q1',
  'type': 'quotation_docx',
  'data': {
    'title': '模板报价',
    'date': '2026-10-08',
    'contact': '张经理',
    'phone': '10000000000',
    'tax_rate': '13',
    'shipping': '含运费',
    'generated': generated,
    'items': [
      {'name': 'BBU模板', 'price': '6350', 'unit': '吨'},
    ],
  },
};
void main() {
  testWidgets('卡片提交编辑后的产品并保留可选费用为空', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(api: api, runId: 'r1', artifact: quote()),
          ),
        ),
      ),
    );
    await tester.tap(find.byKey(const Key('quote-edit')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const ValueKey('quote-items.0.price')),
    );
    await tester.enterText(
      find.byKey(const ValueKey('quote-items.0.price')),
      '6400',
    );
    await tester.ensureVisible(find.text('确认并生成盖章报价单'));
    await tester.tap(find.text('确认并生成盖章报价单'));
    await tester.pumpAndSettle();
    expect(api.path, '/ai/runs/r1/quotations/q1');
    expect(api.payload!['items'][0]['price'], '6400');
    expect(api.payload!['items'][0]['material_price'], null);
    expect(find.text('下载盖章报价单 DOCX'), findsOneWidget);
  });
  testWidgets('生成失败保留编辑内容并可重试', (tester) async {
    final api = QuoteApi()..fail = true;
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(api: api, runId: 'r1', artifact: quote()),
          ),
        ),
      ),
    );
    await tester.tap(find.byKey(const Key('quote-edit')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const ValueKey('quote-items.0.price')),
    );
    await tester.enterText(
      find.byKey(const ValueKey('quote-items.0.price')),
      '6500',
    );
    await tester.ensureVisible(find.text('确认并生成盖章报价单'));
    await tester.tap(find.text('确认并生成盖章报价单'));
    await tester.pumpAndSettle();
    expect(find.text('请核对计价单位'), findsOneWidget);
    api.fail = false;
    await tester.tap(find.text('确认并生成盖章报价单'));
    await tester.pumpAndSettle();
    expect(api.payload!['items'][0]['price'], '6500');
    expect(find.text('下载盖章报价单 DOCX'), findsOneWidget);
  });
  testWidgets('业务菜单保留报价预填且无上传权限不显示合同入口', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(MaterialApp(home: AssistantPage(api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('业务操作'));
    await tester.pumpAndSettle();
    expect(find.text('上传合同'), findsNothing);
    await tester.tap(find.text('快速制作报价单'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('ai-input')))
          .controller!
          .text,
      contains('做个报价单'),
    );
    expect(api.path, isNull);
    await tester.tap(find.byTooltip('历史会话'));
    await tester.pumpAndSettle();
    expect(find.text('最近会话'), findsOneWidget);
    expect(find.text('我的报价'), findsOneWidget);
    expect(find.text('上传合同'), findsNothing);
    await tester.tap(find.text('新会话'));
    await tester.pumpAndSettle();
    expect(find.text('最近会话'), findsNothing);
  });
  testWidgets('授权上传在侧栏和操作菜单均保留，键盘弹起仍可发送', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final api = QuoteApi()..session = {'can_upload_contracts': true};
    addTearDown(api.dispose);
    await tester.pumpWidget(MaterialApp(home: AssistantPage(api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('历史会话'));
    await tester.pumpAndSettle();
    expect(find.text('上传合同'), findsOneWidget);
    await tester.tap(find.text('新会话'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('业务操作'));
    await tester.pumpAndSettle();
    expect(find.text('上传合同'), findsOneWidget);
    Navigator.of(tester.element(find.text('上传合同'))).pop();
    await tester.pumpAndSettle();
    tester.view.viewInsets = const FakeViewPadding(bottom: 300);
    addTearDown(tester.view.resetViewInsets);
    await tester.enterText(find.byKey(const Key('ai-input')), '查询项目');
    await tester.pumpAndSettle();
    expect(
      tester.getBottomRight(find.byKey(const Key('ai-send'))).dy,
      lessThan(544),
    );
    api.fail = true;
    await tester.tap(find.byKey(const Key('ai-send')));
    await tester.pumpAndSettle();
    expect(find.text('请核对计价单位'), findsOneWidget);
    expect(tester.getBottomRight(find.text('请核对计价单位')).dy, lessThan(544));
    expect(tester.takeException(), isNull);
  });
  testWidgets('AI等待显示转圈且完成后消失，保留发送禁用状态', (tester) async {
    final api = PendingReplyApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(MaterialApp(home: AssistantPage(api: api)));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('ai-input')), '查询项目');
    await tester.pump();
    await tester.tap(find.byKey(const Key('ai-send')));
    await tester.pump();
    expect(find.byType(AssistantThinking), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNWidgets(2));
    expect(
      tester.widget<IconButton>(find.byKey(const Key('ai-send'))).onPressed,
      isNull,
    );
    api.response.complete({'answer': '报价**6350元**', 'conversations': []});
    await tester.pumpAndSettle();
    expect(find.byType(AssistantThinking), findsNothing);
    expect(find.byType(CircularProgressIndicator), findsNothing);
    final reply = tester.widget<SelectableText>(
      find.descendant(
        of: find.byType(AssistantReplyText),
        matching: find.byType(SelectableText),
      ),
    );
    expect(reply.textSpan!.toPlainText(), '报价6350元');
    expect(reply.textSpan!.children!.length, 2);
  });
  testWidgets('回复普通文本和不成对星号保持原文', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: AssistantReplyText(text: '价格 *待核对，正文不变')),
      ),
    );
    expect(
      tester
          .widget<SelectableText>(find.byType(SelectableText))
          .textSpan!
          .toPlainText(),
      '价格 *待核对，正文不变',
    );
  });
  testWidgets('缺项定位字段并保留内容，补齐后才调用生成接口', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    final artifact = quote();
    final data = artifact['data'] as Map<String, dynamic>;
    data['title'] = '';
    (data['items'] as List).first['unit'] = '';
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(api: api, runId: 'r1', artifact: artifact),
          ),
        ),
      ),
    );
    await tester.tap(find.text('确认并生成盖章报价单'));
    await tester.pumpAndSettle();
    expect(api.path, isNull);
    expect(find.text('请填写报价标题'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('quote-title')), '测试报价');
    await tester.ensureVisible(find.text('确认并生成盖章报价单'));
    await tester.tap(find.text('确认并生成盖章报价单'));
    await tester.pumpAndSettle();
    expect(api.path, isNull);
    expect(find.text('请选择计价单位'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('quote-items.0.unit')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('吨').last);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('确认并生成盖章报价单'));
    await tester.tap(find.text('确认并生成盖章报价单'));
    await tester.pumpAndSettle();
    expect(api.payload!['title'], '测试报价');
    expect(api.payload!['items'][0]['unit'], '吨');
    expect(find.byType(TextFormField), findsNothing);
    expect(find.text('下载盖章报价单 PDF'), findsOneWidget);
  });
  testWidgets('历史会话恢复已生成报价下载卡', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(MaterialApp(home: AssistantPage(api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('历史会话'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('我的报价'));
    await tester.pumpAndSettle();
    expect(find.byType(QuotationCard), findsOneWidget);
    expect(find.text('下载盖章报价单 DOCX'), findsOneWidget);
  });
  testWidgets('生成后支持预览入口和PDF Word分享，失败可重试', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    const channel = MethodChannel('palantir/android');
    var failShare = false;
    final calls = <MethodCall>[];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          calls.add(call);
          if (failShare) {
            throw PlatformException(code: 'share', message: '无法分享');
          }
          return null;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(
              api: api,
              runId: 'r1',
              artifact: quote(generated: true),
            ),
          ),
        ),
      ),
    );
    expect(find.byKey(const ValueKey('quote-preview')), findsOneWidget);
    for (final format in ['pdf', 'docx']) {
      await tester.ensureVisible(find.byKey(const ValueKey('quote-share')));
      await tester.tap(find.byKey(const ValueKey('quote-share')));
      await tester.pumpAndSettle();
      await tester.tap(find.text(format == 'pdf' ? '分享 PDF（推荐）' : '分享 Word'));
      await tester.pumpAndSettle();
      expect(
        api.path,
        '/ai/runs/r1/quotations/q1/download${format == 'pdf' ? '?format=pdf' : ''}',
      );
      expect(calls.last.method, 'shareFile');
      expect(calls.last.arguments['name'], '报价单.$format');
      expect(
        calls.last.arguments['mime'],
        format == 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      );
    }
    failShare = true;
    await tester.ensureVisible(find.byKey(const ValueKey('quote-share')));
    await tester.tap(find.byKey(const ValueKey('quote-share')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('分享 PDF（推荐）'));
    await tester.pumpAndSettle();
    expect(find.textContaining('分享失败，请重试'), findsOneWidget);
    expect(find.text('下载盖章报价单 DOCX'), findsOneWidget);
    expect(find.text('下载盖章报价单 PDF'), findsOneWidget);
  });

  testWidgets('预览使用授权PDF接口并关闭释放文档', (tester) async {
    final api = PreviewQuoteApi();
    addTearDown(api.dispose);
    const channel = MethodChannel('palantir/android');
    final methods = <String>[];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          methods.add(call.method);
          if (call.method == 'openPdf') return {'id': 'pdf1', 'pages': 1};
          if (call.method == 'renderPdf') return Uint8List(0);
          return null;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(
              api: api,
              runId: 'r1',
              artifact: quote(generated: true),
            ),
          ),
        ),
      ),
    );
    await tester.ensureVisible(find.byKey(const ValueKey('quote-preview')));
    await tester.tap(find.byKey(const ValueKey('quote-preview')));
    await tester.pumpAndSettle();
    expect(api.path, '/ai/runs/r1/quotations/q1/download?format=pdf');
    expect(find.text('报价单预览'), findsOneWidget);
    expect(find.byType(InteractiveViewer), findsOneWidget);
    expect(methods, containsAllInOrder(['openPdf', 'renderPdf']));
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(methods.last, 'closePdf');
    expect(find.text('下载盖章报价单 DOCX'), findsOneWidget);
  });

  testWidgets('未生成报价不显示分享与预览', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: QuotationCard(api: api, runId: 'r1', artifact: quote()),
        ),
      ),
    );
    expect(find.byKey(const ValueKey('quote-share')), findsNothing);
    expect(find.byKey(const ValueKey('quote-preview')), findsNothing);
  });

  testWidgets('PDF通过登录下载并以正确类型保存', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    const channel = MethodChannel('palantir/android');
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          expect(call.method, 'saveFile');
          expect(call.arguments['name'], '报价单.pdf');
          expect(call.arguments['mime'], 'application/pdf');
          return true;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(
              api: api,
              runId: 'r1',
              artifact: quote(generated: true),
            ),
          ),
        ),
      ),
    );
    await tester.ensureVisible(find.text('下载盖章报价单 PDF'));
    await tester.tap(find.text('下载盖章报价单 PDF'));
    await tester.pumpAndSettle();
    expect(api.path, '/ai/runs/r1/quotations/q1/download?format=pdf');
    expect(find.text('报价单已保存到所选位置'), findsOneWidget);
  });
  testWidgets('取消系统保存不会显示已保存', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    const channel = MethodChannel('palantir/android');
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          expect(call.method, 'saveFile');
          return false;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: QuotationCard(
              api: api,
              runId: 'r1',
              artifact: quote(generated: true),
            ),
          ),
        ),
      ),
    );
    await tester.ensureVisible(find.text('下载盖章报价单 DOCX'));
    await tester.tap(find.text('下载盖章报价单 DOCX'));
    await tester.pumpAndSettle();
    expect(api.path, '/ai/runs/r1/quotations/q1/download');
    expect(find.text('已取消保存，可重新下载'), findsOneWidget);
  });
  testWidgets('报价走异步任务而普通查询仍走原有接口', (tester) async {
    final api = QuoteApi();
    addTearDown(api.dispose);
    await tester.pumpWidget(MaterialApp(home: AssistantPage(api: api)));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('ai-input')),
      '做个报价单 BBU模板6350元/吨',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('ai-send')));
    await tester.pumpAndSettle();
    expect(api.path, '/ai/runs');
    expect(
      api.payload!['client_request_id'],
      matches(RegExp(r'^[a-f0-9-]{36}$')),
    );
    expect(find.byType(QuotationCard), findsOneWidget);
    await tester.enterText(find.byKey(const Key('ai-input')), '查项目');
    await tester.pump();
    await tester.tap(find.byKey(const Key('ai-send')));
    await tester.pumpAndSettle();
    expect(api.path, '/ai/messages');
  });
}
