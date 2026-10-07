import 'package:flutter/material.dart';

import 'api.dart';
import 'attachments.dart';

class QuotationCard extends StatefulWidget {
  final PalantirApi api;
  final String runId;
  final Map<String, dynamic> artifact;
  const QuotationCard({
    super.key,
    required this.api,
    required this.runId,
    required this.artifact,
  });
  @override
  State<QuotationCard> createState() => _QuotationCardState();
}

class _QuotationCardState extends State<QuotationCard> {
  late Map<String, dynamic> artifact;
  late Map<String, dynamic> values;
  bool busy = false;
  String? feedback;
  String get endpoint =>
      '/ai/runs/${Uri.encodeComponent(widget.runId)}/quotations/${Uri.encodeComponent('${artifact['id']}')}';
  bool get generated => mapOf(artifact['data'])['generated'] == true;
  @override
  void initState() {
    super.initState();
    artifact = widget.artifact;
    values = {...mapOf(artifact['data'])};
    values['tax_rate'] ??= '13';
    values['shipping'] ??= '含运费';
    values['items'] = maps(values['items'])
        .map((item) => <String, dynamic>{...item})
        .toList();
    if ((values['items'] as List).isEmpty) {
      values['items'] = <Map<String, dynamic>>[{}];
    }
  }

  Widget field(Map<String, dynamic> target, String key, String label) =>
      Padding(
        padding: const EdgeInsets.only(top: 10),
        child: TextFormField(
          initialValue: '${target[key] ?? ''}',
          enabled: !busy && !generated,
          decoration: InputDecoration(
            labelText: label,
            border: const OutlineInputBorder(),
          ),
          onChanged: (value) => target[key] = value,
        ),
      );
  Future<void> act() async {
    setState(() {
      busy = true;
      feedback = null;
    });
    try {
      if (generated) {
        final bytes = await widget.api.download('$endpoint/download');
        final saved = await android.invokeMethod<bool>('saveFile', {
          'bytes': bytes,
          'name': '报价单.docx',
          'mime': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        });
        if (mounted) {
          setState(
            () => feedback = saved == true ? '报价单已保存到所选位置' : '已取消保存，可重新下载',
          );
        }
      } else {
        final payload = {
          ...values,
          'items': maps(values['items'])
              .map(
                (item) => {
                  ...item,
                  'material_price': '${item['material_price'] ?? ''}'.isEmpty
                      ? null
                      : item['material_price'],
                  'processing_price':
                      '${item['processing_price'] ?? ''}'.isEmpty
                      ? null
                      : item['processing_price'],
                },
              )
              .toList(),
        };
        final response = await widget.api.send(endpoint, payload);
        if (mounted) {
          setState(() {
            artifact = mapOf(response['artifact']);
            widget.artifact.addAll(artifact);
          });
        }
      }
    } catch (e) {
      if (mounted) setState(() => feedback = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.transparent,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text('固定模板报价单', style: TextStyle(fontWeight: FontWeight.bold)),
        for (final pair in [
          ['title', '报价标题'],
          ['date', '报价日期（YYYY-MM-DD）'],
          ['contact', '联系人'],
          ['phone', '联系电话'],
        ])
          field(values, pair[0], pair[1]),
        for (final item in (values['items'] as List<Map<String, dynamic>>)) ...[
          field(item, 'name', '物资名称'),
          field(item, 'price', '综合单价'),
          field(item, 'unit', '计价单位（吨、套等）'),
          ExpansionTile(
            title: const Text('材料费、加工费（可选）'),
            children: [
              field(item, 'material_price', '材料费'),
              field(item, 'processing_price', '加工费'),
            ],
          ),
        ],
        if (!generated && (values['items'] as List).length < 3)
          TextButton(
            onPressed: busy
                ? null
                : () => setState(
                    () => (values['items'] as List).add(<String, dynamic>{}),
                  ),
            child: const Text('添加产品'),
          ),
        field(values, 'tax_rate', '税率（模板固定13%）'),
        field(values, 'shipping', '运费口径（模板固定含运费）'),
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 10),
          child: Text('保留原模板排版与公章，导出文字全部为黑色。请核对单价和单位后生成。'),
        ),
        if (feedback != null) Text(feedback!),
        if (busy) const LinearProgressIndicator(),
        FilledButton(
          onPressed: busy ? null : act,
          child: Text(generated ? '下载盖章报价单 DOCX' : '确认并生成盖章报价单'),
        ),
      ],
    ),
  );
}
